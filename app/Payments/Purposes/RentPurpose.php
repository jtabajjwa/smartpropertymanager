<?php

namespace App\Payments\Purposes;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Payments\Contracts\PaymentPurpose;
use App\Payments\Msisdn;
use App\Services\CurrentLandlord;
use App\Services\NumberGenerator;
use App\Services\SmsNotificationService;
use Illuminate\Support\Facades\DB;

/**
 * A tenant paying their landlord by mobile money, into the Primora pool
 * (settled to the landlord later). On success it is recorded in the
 * landlord's books as a tenant payment: allocated to the chosen invoice,
 * or left unallocated as an advance / credit.
 *
 * Context saved on the transaction: tenant_id, tenant_name, invoice_id,
 * invoice_number, principal (what counts towards rent), fee, fee_bearer;
 * payment_id once recorded. Paid by USSD (channel "ussd"): lease_id, and the
 * fee is worked out from what was actually paid; the payment goes against
 * that lease's oldest unpaid invoices.
 */
class RentPurpose implements PaymentPurpose
{
    public function code(): string
    {
        return 'RNT';
    }

    public function describe(PaymentTransaction $transaction): string
    {
        $c = $transaction->context ?? [];
        $what = !empty($c['invoice_number']) ? "Invoice {$c['invoice_number']}" : 'Advance payment';
        $landlordName = $transaction->landlord?->name ?? 'Landlord #'.$transaction->landlord_id;

        return "{$what} | ".($c['tenant_name'] ?? 'Tenant')." | {$landlordName}";
    }

    public function onSucceeded(PaymentTransaction $transaction): void
    {
        if (!isset($transaction->context['principal'])) {
            $this->splitFee($transaction);
        }

        $payment = CurrentLandlord::runAs($transaction->landlord_id, fn () => $this->record($transaction));

        $transaction->update(['context' => ($transaction->context ?? []) + ['payment_id' => $payment->id, 'payment_number' => $payment->number]]);

        // Receipt SMS to the tenant (uses the landlord's SMS credit, like any receipt).
        DB::afterCommit(function () use ($payment, $transaction) {
            try {
                CurrentLandlord::runAs($transaction->landlord_id, fn () => app(SmsNotificationService::class)->paymentReceived($payment->fresh('payable')));
            } catch (\Throwable $e) {
                report($e);
            }
        });
        $this->tellWhatsApp($transaction, true);
    }

    public function onFailed(PaymentTransaction $transaction): void
    {
        // Nothing was recorded for the landlord, so nothing to undo.
        $this->tellWhatsApp($transaction, false);
    }

    /** A payment started from the WhatsApp bot is confirmed in that chat. */
    protected function tellWhatsApp(PaymentTransaction $transaction, bool $succeeded): void
    {
        if (empty($transaction->context['whatsapp_to'])) {
            return;
        }

        DB::afterCommit(function () use ($transaction, $succeeded) {
            try {
                \App\WhatsApp\TenantBot::paymentResult($transaction->fresh(), $succeeded);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /** For a payment whose amount was chosen outside Primora (USSD): rent part and fee, from what was paid. */
    protected function splitFee(PaymentTransaction $transaction): void
    {
        $split = app(\App\Services\CollectionFees::class)->fromPaid($transaction->landlord, (float) $transaction->amount);

        $transaction->update([
            'fee_amount' => $split['fee'],
            'fee_bearer' => $split['bearer'],
            'landlord_amount' => $split['landlord_amount'],
            'context' => ($transaction->context ?? []) + ['principal' => $split['principal'], 'fee' => $split['fee'], 'fee_bearer' => $split['bearer']],
        ]);
    }

    protected function record(PaymentTransaction $transaction): Payment
    {
        $c = $transaction->context ?? [];
        $principal = (float) ($c['principal'] ?? $transaction->amount);
        $tenant = Tenant::findOrFail($c['tenant_id'] ?? $transaction->payable_id);

        $payment = Payment::create([
            'landlord_id' => $transaction->landlord_id,
            'payable_type' => Tenant::class,
            'payable_id' => $tenant->id,
            'amount' => $principal,
            'payment_date' => ($transaction->completed_at ?? now())->toDateString(),
            'payment_method' => Msisdn::network($transaction->msisdn) === 'Airtel' ? 'airtel_mobile_money' : 'mtn_mobile_money',
            'reference' => $transaction->network_reference ?: ($transaction->receipt_number ?: $transaction->reference),
            'notes' => 'Paid by the tenant through Primora'.(($c['channel'] ?? null) === 'ussd' ? ' (USSD)' : '')." ({$transaction->reference}) from {$transaction->displayMsisdn()}.",
            'status' => 'unallocated',
        ]);
        $payment->update(['number' => app(NumberGenerator::class)->next($transaction->landlord_id, 'payment', 'PAY')]);

        if (!empty($c['invoice_id'])) {
            $invoice = Invoice::whereKey($c['invoice_id'])->lockForUpdate()->first();

            if ($invoice && !in_array($invoice->status, ['cancelled', 'paid', 'written_off'], true)) {
                $outstanding = (float) bcsub((string) $invoice->amount, (string) $invoice->allocations()->sum('amount'), 2);
                $allocate = min($principal, $outstanding);

                if ($allocate > 0) {
                    PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $allocate]);
                    $invoice->syncStatus();
                }
            }
        }

        // Paid against a tenancy rather than one invoice: oldest unpaid first.
        if (empty($c['invoice_id']) && !empty($c['lease_id'])) {
            $left = $principal;
            $invoices = Invoice::where('lease_id', $c['lease_id'])->whereIn('status', ['unpaid', 'partial'])
                ->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();

            foreach ($invoices as $invoice) {
                if ($left <= 0) {
                    break;
                }
                $outstanding = (float) bcsub((string) $invoice->amount, (string) $invoice->allocations()->sum('amount'), 2);
                $allocate = min($left, $outstanding);

                if ($allocate > 0) {
                    PaymentAllocation::create(['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $allocate]);
                    $invoice->syncStatus();
                    $left = (float) bcsub((string) $left, (string) $allocate, 2);
                }
            }
        }

        $payment->updateAllocationStatus();

        return $payment;
    }
}
