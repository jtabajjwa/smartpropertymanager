<?php

namespace App\Http\Controllers;

use App\Exports\PaymentsExport;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Payment;
use App\Services\CurrentLandlord;
use App\Services\NumberGenerator;
use App\Services\SmsNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function index()
    {
        $query = Payment::with(['payable', 'allocations.invoice']);

        if (request('status')) {
            $query->where('status', request('status'));
        }

        if (request('payment_method')) {
            $query->where('payment_method', request('payment_method'));
        }

        if (request('tenant_id')) {
            $query->where('payable_type', \App\Models\Tenant::class)
                ->where('payable_id', request('tenant_id'));
        }

        if (request('date_from')) {
            $query->whereDate('payment_date', '>=', request('date_from'));
        }

        if (request('date_to')) {
            $query->whereDate('payment_date', '<=', request('date_to'));
        }

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhereHasMorph('payable', [\App\Models\Tenant::class, \App\Models\Customer::class], fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $payments = $query->latest()->paginate(15)->withQueryString();

        return view('payments.index', compact('payments'));
    }

    public function store(StorePaymentRequest $request, \App\Actions\RecordPayment $record)
    {
        $payment = $record($request->validated(), $request->file('receipt_photo'));

        return redirect()->back()->with('success', "Payment {$payment->number} recorded.")->with('receipt_payment_id', $payment->id);
    }

    /** A printable receipt (PDF) for the payment. */
    public function receipt(Payment $payment, \App\Services\PaymentReceipt $receipt)
    {
        return $receipt->pdf($payment)->stream(\App\Services\PaymentReceipt::filename($payment));
    }

    public function export(Request $request)
    {
        return (new PaymentsExport($request))->download('payments.csv');
    }

    public function cancel(Payment $payment)
    {
        if ($payment->status === 'cancelled') {
            return redirect()->back()->with('error', 'Payment is already cancelled.');
        }

        if ($payment->allocations()->exists()) {
            return redirect()->back()->with('error', 'Cannot cancel a payment with existing allocations. Remove allocations first.');
        }

        $payment->update(['status' => 'cancelled']);

        return redirect()->back()->with('success', 'Payment cancelled successfully.');
    }
}
