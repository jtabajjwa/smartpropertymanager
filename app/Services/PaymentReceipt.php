<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Customer;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\AmountInWords;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A receipt for a payment (landlords print it for cash; tenants download it):
 * who paid, how, what it paid for (each invoice, and what is still owed on
 * it), any amount kept as advance payment, and the payer's balance now.
 */
class PaymentReceipt
{
    public function __construct(protected AccountBalances $balances)
    {
    }

    public function data(Payment $payment): array
    {
        $payment->loadMissing(['landlord', 'payable', 'allocations.invoice.lease.unit.estate']);

        // What this payment paid, invoice by invoice, with what is still owed on each now.
        $lines = $payment->allocations->sortBy(fn ($a) => [$a->invoice?->due_date, $a->invoice_id])->values()->map(function ($a) {
            $invoice = $a->invoice;
            $paidOnInvoice = $invoice ? (float) $invoice->allocations()->sum('amount') : 0;

            return [
                'number' => $invoice?->number,
                'title' => $invoice?->title ?? 'Invoice',
                'unit' => $invoice?->lease?->unit ? trim(($invoice->lease->unit->estate?->name ? $invoice->lease->unit->estate->name.' · ' : '').$invoice->lease->unit->name) : null,
                'invoice_amount' => (float) ($invoice?->amount ?? 0),
                'paid_now' => (float) $a->amount,
                'balance' => $invoice && !in_array($invoice->status, ['cancelled', 'written_off'], true) ? max(0.0, (float) $invoice->amount - $paidOnInvoice) : 0.0,
            ];
        });

        $advance = max(0.0, (float) $payment->amount - $lines->sum('paid_now'));

        // The payer's account today.
        $owed = $credit = null;
        $units = collect();
        if ($payment->payable_type === Tenant::class && $payment->payable) {
            $b = $this->balances->forTenants([$payment->payable_id])[$payment->payable_id] ?? AccountBalances::EMPTY;
            $owed = max(0.0, $b['owed'] - $b['credit']);
            $credit = max(0.0, $b['credit'] - $b['owed']);
            $units = Lease::where('tenant_id', $payment->payable_id)->where('status', 'active')->with('unit.estate')->get()
                ->map(fn ($l) => $l->unit ? trim(($l->unit->estate?->name ? $l->unit->estate->name.' · ' : '').$l->unit->name) : null)->filter()->unique()->values();
        }
        if ($units->isEmpty()) {
            $units = $lines->pluck('unit')->filter()->unique()->values();
        }

        return [
            'payment' => $payment,
            'landlord' => $payment->landlord,
            'payer' => $payment->display_payer_name,
            'payerPhone' => $payment->payable_type === Tenant::class ? $payment->payable?->primary_phone : ($payment->payable_type === Customer::class ? $payment->payable?->phone : null),
            'units' => $units,
            'method' => self::methodLabel($payment->payment_method),
            'lines' => $lines,
            'advance' => $advance,
            'owed' => $owed,
            'credit' => $credit,
            'inWords' => AmountInWords::shillings($payment->amount),
            'receivedBy' => $this->receivedBy($payment),
            'online' => str_contains((string) $payment->notes, 'through Primora'),
        ];
    }

    public function pdf(Payment $payment)
    {
        return Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true])
            ->loadView('pdfs.receipt', $this->data($payment))
            ->setPaper('a5', 'portrait');
    }

    public static function filename(Payment $payment): string
    {
        return 'receipt-'.($payment->number ?: $payment->id).'.pdf';
    }

    public static function methodLabel(?string $method): string
    {
        return [
            'cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mtn_mobile_money' => 'MTN Mobile Money',
            'airtel_mobile_money' => 'Airtel Money', 'flexipay' => 'FlexiPay',
        ][$method] ?? ucwords(str_replace('_', ' ', (string) $method));
    }

    /** The landlord's user who recorded it (from the audit log), if any. */
    protected function receivedBy(Payment $payment): ?string
    {
        return Audit::where('auditable_type', Payment::class)->where('auditable_id', $payment->id)->where('action', 'created')
            ->whereNotNull('user_id')->with('user:id,name')->first()?->user?->name;
    }
}
