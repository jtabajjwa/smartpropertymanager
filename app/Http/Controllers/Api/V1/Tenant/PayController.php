<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Payments\PaymentService;
use App\Services\TenantPortal\RentPaymentException;
use App\Services\TenantPortal\RentPayments;
use App\Services\TenantPortal\TenantAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A tenant paying an invoice, or in advance, by mobile money (into the Primora pool), as on the web tenant portal. */
class PayController extends Controller
{
    public function __construct(protected RentPayments $rent)
    {
    }

    public function options(Request $request)
    {
        $tenant = $request->user();
        $account = new TenantAccount($tenant);

        return response()->json([
            'enabled' => $this->rent->available($tenant),
            'reason' => !$tenant->landlord->collect_payments ? "Your landlord doesn't accept mobile money payments through Primora yet." : null,
            'open_invoices' => $account->openInvoices()->map(fn ($i) => AccountController::invoice($i))->values(),
            'pass_fee' => (bool) $tenant->landlord->pass_collection_fee,
            'default_phone' => $tenant->primary_phone,
            'min' => RentPayments::MIN_AMOUNT,
            'max' => RentPayments::MAX_AMOUNT,
            'credit' => round($account->credit(), 2),
        ]);
    }

    /** What paying this much costs, with the collection fee when the landlord passes it on. */
    public function quote(Request $request)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0', 'max:'.RentPayments::MAX_AMOUNT * 2]]);

        return response()->json($this->rent->quote($request->user(), (float) $data['amount']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        try {
            $t = $this->rent->start($request->user(), !empty($data['invoice_id']) ? (int) $data['invoice_id'] : null, (float) $data['amount'], $data['phone']);
        } catch (RentPaymentException $e) {
            throw ValidationException::withMessages([$e->field ?? 'phone' => $e->getMessage()]);
        }

        return response()->json([
            'reference' => $t->reference,
            'status' => $t->status,
            'message' => $t->status === PaymentTransaction::SUCCEEDED ? 'Payment received. Thank you!' : 'Approve the payment on '.$t->displayMsisdn().' with your PIN.',
        ], 201);
    }

    /** Polled while a payment awaits approval; checks with the gateway at most every 5 seconds. */
    public function status(Request $request, string $reference, PaymentService $payments)
    {
        $t = AccountController::rentTransactions($request->user())->where('reference', $reference)->firstOrFail();

        if ($t->isOpen() && (!$t->last_checked_at || $t->last_checked_at->lt(now()->subSeconds(5)))) {
            try {
                $t = $payments->refresh($t);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(self::transaction($t));
    }

    public static function transaction(PaymentTransaction $t): array
    {
        $c = $t->context ?? [];

        return [
            'reference' => $t->reference,
            'date' => $t->created_at?->toIso8601String(),
            'amount' => (float) $t->amount,
            'principal' => (float) ($c['principal'] ?? $t->amount),
            'invoice_number' => $c['invoice_number'] ?? null,
            'phone' => $t->displayMsisdn(),
            'status' => $t->status,
            'open' => $t->isOpen(),
            'message' => $t->status_message,
            'receipt' => $t->network_reference ?: $t->receipt_number,
        ];
    }
}
