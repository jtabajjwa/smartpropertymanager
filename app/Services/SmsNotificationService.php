<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\SecurityDepositLedger;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Log;

class SmsNotificationService
{
    protected SmsService $sms;

    public function __construct(SmsService $sms, protected SmsCredits $credits)
    {
        $this->sms = $sms;
    }

    /**
     * Scenario 1: New invoice created
     */
    public function invoiceCreated(Invoice $invoice): void
    {
        if ($invoice->invoiceable_type === \App\Models\Customer::class) {
            $payer = $invoice->invoiceable;
            $phone = $payer?->phone;
            $tenantId = null;
        } else {
            $payer = $invoice->lease?->tenant;
            $phone = $payer?->primary_phone;
            $tenantId = $payer?->id;
        }

        if (!$phone) return;

        $app = config('app.name');
        $message = "Dear {$payer->name}, invoice {$invoice->number} for {$invoice->title} (UGX " . number_format((float) $invoice->amount) . ") has been issued. Due: {$invoice->due_date->format('d M Y')}. - {$app}";

        $this->sendAndLog($phone, $message, $tenantId, 'invoice_created', 'Invoice', $invoice->id);
    }

    /**
     * Scenario 2: Invoice overdue reminder (scheduled)
     */
    public function invoiceOverdue(Invoice $invoice): void
    {
        if ($invoice->invoiceable_type === \App\Models\Customer::class) {
            $payer = $invoice->invoiceable;
            $phone = $payer?->phone;
            $tenantId = null;
        } else {
            $payer = $invoice->lease?->tenant;
            $phone = $payer?->primary_phone;
            $tenantId = $payer?->id;
        }

        if (!$phone) return;

        if (SmsLog::alreadySentToday('overdue_reminder', 'Invoice', $invoice->id)) return;

        $app = config('app.name');
        $message = "Dear {$payer->name}, invoice {$invoice->number} (UGX " . number_format((float) $invoice->amount) . ") was due on {$invoice->due_date->format('d M Y')} and is now overdue. Please settle your balance. - {$app}";

        $this->sendAndLog($phone, $message, $tenantId, 'overdue_reminder', 'Invoice', $invoice->id);
    }

    /**
     * Scenario 3: Payment received
     */
    public function paymentReceived(Payment $payment): void
    {
        $payer = $payment->payable;
        if (!$payer) return;

        $phone = $payer instanceof \App\Models\Tenant ? $payer->primary_phone : ($payer instanceof \App\Models\Customer ? $payer->phone : null);
        $tenantId = $payer instanceof \App\Models\Tenant ? $payer->id : null;

        if (!$phone) return;

        $app = config('app.name');
        $ref = $payment->reference ? " (Ref: {$payment->reference})" : '';
        $message = "Dear {$payer->name}, payment of UGX " . number_format((float) $payment->amount) . " received{$ref}. Thank you! - {$app}";

        $this->sendAndLog($phone, $message, $tenantId, 'payment_received', 'Payment', $payment->id);
    }

    /**
     * Scenario 4: Lease expiring soon (scheduled)
     */
    public function leaseExpiring(Lease $lease): void
    {
        $tenant = $lease->tenant;
        if (! $tenant?->primary_phone) return;

        if (SmsLog::alreadySentToday('lease_expiring', 'Lease', $lease->id)) return;

        $app = config('app.name');
        $unit = $lease->unit?->name ?? 'your unit';
        $message = "Dear {$tenant->name}, your lease for {$unit} expires on {$lease->end_date->format('d M Y')}. Please contact management for renewal. - {$app}";

        $this->sendAndLog($tenant->primary_phone, $message, $tenant->id, 'lease_expiring', 'Lease', $lease->id);
    }

    /**
     * Scenario 5: Security deposit deduction
     */
    public function depositDeduction(SecurityDepositLedger $entry, float $remainingBalance): void
    {
        $tenant = $entry->lease?->tenant;
        if (! $tenant?->primary_phone) return;

        $app = config('app.name');
        $notes = $entry->notes ? " Reason: {$entry->notes}." : '';
        $message = "Dear {$tenant->name}, UGX " . number_format((float) $entry->amount) . " deducted from your security deposit.{$notes} Remaining: UGX " . number_format($remainingBalance) . ". - {$app}";

        // Truncate if too long (notes can push it over)
        $message = mb_substr($message, 0, 320);

        $this->sendAndLog($tenant->primary_phone, $message, $tenant->id, 'deposit_deduction', 'SecurityDepositLedger', $entry->id);
    }

    /**
     * Scenario 6: Security deposit refund
     */
    public function depositRefund(SecurityDepositLedger $entry, float $remainingBalance): void
    {
        $tenant = $entry->lease?->tenant;
        if (! $tenant?->primary_phone) return;

        $app = config('app.name');
        $message = "Dear {$tenant->name}, UGX " . number_format((float) $entry->amount) . " refunded from your security deposit. Remaining: UGX " . number_format($remainingBalance) . ". - {$app}";

        $this->sendAndLog($tenant->primary_phone, $message, $tenant->id, 'deposit_refund', 'SecurityDepositLedger', $entry->id);
    }

    /**
     * Scenario 8: Upcoming invoice reminder (scheduled, before due date)
     */
    public function invoiceUpcoming(Invoice $invoice): void
    {
        if ($invoice->invoiceable_type === \App\Models\Customer::class) {
            $payer = $invoice->invoiceable;
            $phone = $payer?->phone;
            $tenantId = null;
        } else {
            $payer = $invoice->lease?->tenant;
            $phone = $payer?->primary_phone;
            $tenantId = $payer?->id;
        }

        if (!$phone) return;

        if (SmsLog::alreadySentToday('upcoming_reminder', 'Invoice', $invoice->id)) return;

        $app = config('app.name');
        $message = "Dear {$payer->name}, reminder: invoice {$invoice->number} (UGX " . number_format((float) $invoice->amount) . ") is due on {$invoice->due_date->format('d M Y')}. Please ensure timely payment. - {$app}";

        $this->sendAndLog($phone, $message, $tenantId, 'upcoming_reminder', 'Invoice', $invoice->id);
    }

    /**
     * Send a landlord notification SMS, paid for from the landlord's SMS
     * credits (one credit per 160-character part). No credit, no SMS: it is
     * logged as "no_credit" instead. Credit is returned if the send fails.
     */
    protected function sendAndLog(string $phone, string $message, ?int $tenantId, string $type, string $refType, int $refId): void
    {
        $message = mb_substr($message, 0, 320); // what SmsService actually sends
        $segments = SmsSegments::count($message);
        $landlordId = CurrentLandlord::id();
        $log = null;
        $reserved = false;

        // A read-only account (trial or plan ended) sends no SMS; the log shows why.
        if ($landlordId && \App\Models\Landlord::find($landlordId)?->isReadOnly()) {
            SmsLog::create([
                'phone' => $phone, 'message' => $message, 'segments' => $segments, 'tenant_id' => $tenantId,
                'notification_type' => $type, 'reference_type' => $refType, 'reference_id' => $refId,
                'status' => 'read_only', 'error' => 'Not sent: the account is read-only until a plan is chosen.',
            ]);

            return;
        }

        try {
            $log = SmsLog::create([
                'phone' => $phone,
                'message' => $message,
                'segments' => $segments,
                'tenant_id' => $tenantId,
                'notification_type' => $type,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'status' => 'pending',
            ]);

            $reserved = $landlordId && $this->credits->reserve($landlordId, $segments, $log);

            if (!$reserved) {
                $balance = $landlordId ? $this->credits->balance($landlordId) : 0;
                $log->update(['status' => 'no_credit', 'error' => "Not enough SMS credit: needs {$segments}, balance {$balance}."]);

                return;
            }

            $success = $this->sms->send($phone, $message);

            if (!$success) {
                $this->credits->release($landlordId, $segments, $log);
                $reserved = false;
            }

            $log->update([
                'status' => $success ? 'sent' : 'failed',
                'credits_charged' => $success ? $segments : 0,
                'error' => $success ? null : 'API returned failure',
            ]);
        } catch (\Throwable $e) {
            Log::error("SMS notification ({$type}) failed: {$e->getMessage()}");

            try {
                if ($reserved && $log) {
                    $this->credits->release($landlordId, $segments, $log);
                }
                $log?->update(['status' => 'failed', 'credits_charged' => 0, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            } catch (\Throwable $logError) {
                Log::error("SMS log write also failed: {$logError->getMessage()}");
            }
        }
    }
}
