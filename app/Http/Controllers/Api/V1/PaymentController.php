<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\PaymentReceipt;
use Illuminate\Http\Request;

/** Payments received: list, one payment with the invoices it paid, recording one, and its PDF receipt. */
class PaymentController extends Controller
{
    public const METHODS = [
        'cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mtn_mobile_money' => 'MTN Mobile Money',
        'airtel_mobile_money' => 'Airtel Money', 'flexipay' => 'FlexiPay',
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));

        $payments = Payment::with('payable')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('payment_method'), fn ($q, $m) => $q->where('payment_method', $m))
            ->when($request->input('tenant_id'), fn ($q, $id) => $q->where('payable_type', Tenant::class)->where('payable_id', $id))
            ->when($request->input('date_from'), fn ($q, $d) => $q->whereDate('payment_date', '>=', $d))
            ->when($request->input('date_to'), fn ($q, $d) => $q->whereDate('payment_date', '<=', $d))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%")
                ->orWhereHasMorph('payable', [Tenant::class, \App\Models\Customer::class], fn ($t) => $t->where('name', 'like', "%{$search}%"))))
            ->latest('payment_date')->latest('id')->paginate(30);

        return response()->json($payments->through(fn (Payment $p) => self::summary($p)));
    }

    public function show(Payment $payment)
    {
        $payment->load(['payable', 'allocations.invoice']);

        return response()->json(self::summary($payment) + [
            'notes' => $payment->notes,
            'unallocated' => round($payment->unallocatedAmount(), 2),
            'invoices' => $payment->allocations->map(fn ($a) => ['invoice_id' => $a->invoice_id, 'number' => $a->invoice?->number,
                'title' => $a->invoice?->title, 'applied' => (float) $a->amount])->values(),
        ]);
    }

    public function store(StorePaymentRequest $request, RecordPayment $record)
    {
        $payment = $record($request->validated(), $request->file('receipt_photo'));

        return response()->json(self::summary($payment->fresh('payable')), 201);
    }

    /** The PDF receipt (same as the web's). */
    public function receipt(Payment $payment, PaymentReceipt $receipts)
    {
        return $receipts->pdf($payment)->download(PaymentReceipt::filename($payment));
    }

    public static function summary(Payment $p): array
    {
        return [
            'id' => $p->id,
            'number' => $p->number,
            'payer' => $p->display_payer_name,
            'tenant_id' => $p->payable_type === Tenant::class ? $p->payable_id : null,
            'amount' => (float) $p->amount,
            'payment_date' => $p->payment_date?->toDateString(),
            'method' => $p->payment_method,
            'method_label' => self::METHODS[$p->payment_method] ?? ucwords(str_replace('_', ' ', (string) $p->payment_method)),
            'reference' => $p->reference,
            'status' => $p->status,
        ];
    }
}
