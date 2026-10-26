<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\SmsBundle;
use App\Models\SmsCreditTransaction;
use App\Models\SmsLog;
use App\Models\SmsPurchase;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Services\SmsCredits;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SMS reselling: stock bought from providers, the bundles landlords buy,
 * landlord balances, and corrections / offline sales.
 */
class SmsController extends Controller
{
    public function index()
    {
        return view('staff.sms.index', [
            'stats' => self::stockStats(),
            'purchases' => SmsPurchase::with('recordedBy')->latest('purchased_on')->latest('id')->paginate(15, ['*'], 'purchases'),
            'bundles' => SmsBundle::orderBy('sort_order')->orderBy('quantity')->get(),
            'settings' => [
                'starter_credits' => Settings::int('sms.starter_credits', 50),
                'low_balance_threshold' => Settings::int('sms.low_balance_threshold', 20),
            ],
            'salesMonth' => (float) PaymentTransaction::where('purpose', 'sms')->where('status', PaymentTransaction::SUCCEEDED)
                ->where('completed_at', '>=', now()->startOfMonth())->sum('amount'),
        ]);
    }

    /**
     * Stock position. "Sent" is credits actually used by landlords (usage
     * less refunds); "held" is prepaid/free credit sitting in landlord
     * balances, which will be sent eventually.
     */
    public static function stockStats(): array
    {
        $purchased = (int) SmsPurchase::sum('quantity');
        $cost = (float) SmsPurchase::sum('total_cost');
        $sent = (int) -SmsCreditTransaction::whereIn('type', ['usage', 'refund'])->sum('quantity');
        $held = (int) Landlord::sum('sms_balance');

        return [
            'purchased' => $purchased,
            'cost' => $cost,
            'avg_cost' => $purchased > 0 ? $cost / $purchased : null,
            'sent' => $sent,
            'held' => $held,
            // Left with the providers after what's been sent…
            'provider_balance' => $purchased - $sent,
            // …and of that, what isn't already promised to landlords.
            'unallocated' => $purchased - $sent - $held,
            'sent_month' => (int) -SmsCreditTransaction::whereIn('type', ['usage', 'refund'])->where('created_at', '>=', now()->startOfMonth())->sum('quantity'),
            'sold' => (int) SmsCreditTransaction::where('type', 'purchase')->sum('quantity'),
            'given_free' => (int) SmsCreditTransaction::where('type', 'starter')->sum('quantity'),
        ];
    }

    // --- Provider purchases ---------------------------------------------

    public function storePurchase(Request $request)
    {
        $data = $request->validate($this->purchaseRules());

        SmsPurchase::create($data + ['recorded_by_staff_user_id' => $request->user('staff')->id]);

        return back()->with('success', number_format($data['quantity']).' SMS from '.$data['provider'].' recorded.');
    }

    public function updatePurchase(Request $request, SmsPurchase $purchase)
    {
        $purchase->update($request->validate($this->purchaseRules()));

        return back()->with('success', 'Purchase updated.');
    }

    public function destroyPurchase(SmsPurchase $purchase)
    {
        $purchase->delete();

        return back()->with('success', 'Purchase deleted.');
    }

    protected function purchaseRules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000000'],
            'total_cost' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'purchased_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    // --- Bundles ----------------------------------------------------------

    public function storeBundle(Request $request)
    {
        SmsBundle::create($this->bundleData($request));

        return back()->with('success', 'Bundle added.');
    }

    public function updateBundle(Request $request, SmsBundle $bundle)
    {
        $bundle->update($this->bundleData($request));

        return back()->with('success', 'Bundle updated.');
    }

    /** Bundles already bought are kept (for history) and only switched off. */
    public function destroyBundle(SmsBundle $bundle)
    {
        $sold = PaymentTransaction::where('purpose', 'sms')->where('context->bundle_id', $bundle->id)->exists();

        if ($sold) {
            $bundle->update(['is_active' => false]);

            return back()->with('success', 'This bundle has been bought before, so it was hidden from landlords instead of deleted.');
        }

        $bundle->delete();

        return back()->with('success', 'Bundle deleted.');
    }

    protected function bundleData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'price' => ['required', 'numeric', 'min:500', 'max:999999999'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ], ['price.min' => 'Mobile money payments must be at least UGX 500.']);

        return ['sort_order' => (int) ($data['sort_order'] ?? 0), 'is_active' => $request->boolean('is_active')] + $data;
    }

    // --- Settings ---------------------------------------------------------

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'starter_credits' => ['required', 'integer', 'min:0', 'max:100000'],
            'low_balance_threshold' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        Settings::set('sms.starter_credits', (string) $data['starter_credits']);
        Settings::set('sms.low_balance_threshold', (string) $data['low_balance_threshold']);

        return back()->with('success', 'SMS settings saved.');
    }

    // --- Landlord balances --------------------------------------------------

    public function landlords(Request $request)
    {
        $monthStart = now()->startOfMonth();

        $query = Landlord::query()
            ->withSum(['smsCreditTransactions as used_month' => fn ($q) => $q->whereIn('type', ['usage', 'refund'])->where('created_at', '>=', $monthStart)], 'quantity')
            ->withSum(['smsCreditTransactions as bought_total' => fn ($q) => $q->where('type', 'purchase')], 'quantity')
            ->withMax(['smsCreditTransactions as last_bought_at' => fn ($q) => $q->where('type', 'purchase')], 'created_at');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('trading_name', 'like', "%{$search}%")
                ->orWhere('contact_email', 'like', "%{$search}%")
                ->orWhere('contact_phone', 'like', "%{$search}%"));
        }

        $threshold = Settings::int('sms.low_balance_threshold', 20);
        match ($request->input('balance')) {
            'empty' => $query->where('sms_balance', '<=', 0),
            'low' => $query->where('sms_balance', '<=', $threshold),
            default => null,
        };

        return view('staff.sms.landlords', [
            'landlords' => $query->orderBy('sms_balance')->orderBy('name')->paginate(25)->withQueryString(),
            'threshold' => $threshold,
        ]);
    }

    public function landlord(Landlord $landlord)
    {
        $monthStart = now()->startOfMonth();

        return view('staff.sms.landlord', [
            'landlord' => $landlord,
            'ledger' => SmsCreditTransaction::with(['staffUser', 'payment'])->where('landlord_id', $landlord->id)->latest('id')->paginate(25),
            'payments' => PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'sms')->latest('id')->limit(15)->get(),
            'usedMonth' => (int) -SmsCreditTransaction::where('landlord_id', $landlord->id)->whereIn('type', ['usage', 'refund'])->where('created_at', '>=', $monthStart)->sum('quantity'),
            'notSentMonth' => SmsLog::withoutGlobalScopes()->where('landlord_id', $landlord->id)->where('status', 'no_credit')->where('created_at', '>=', $monthStart)->count(),
            'bundles' => SmsBundle::orderBy('sort_order')->orderBy('quantity')->get(),
            'threshold' => Settings::int('sms.low_balance_threshold', 20),
        ]);
    }

    /** Correction (+/−), e.g. goodwill credit or reversing a mistake. */
    public function adjust(Request $request, Landlord $landlord, SmsCredits $credits)
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], ['quantity.not_in' => 'Enter a number of SMS to add (e.g. 100) or remove (e.g. -100).']);

        try {
            $credits->adjust($landlord->id, (int) $data['quantity'], $data['reason'], $request->user('staff'));
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'SMS balance adjusted. New balance: '.number_format($credits->balance($landlord)).' SMS.');
    }

    /** SMS paid for outside Yo! (cash, bank…): counted as SMS sales revenue. */
    public function recordSale(Request $request, Landlord $landlord, PaymentService $payments, SmsCredits $credits)
    {
        $data = $request->validate([
            'bundle_id' => ['nullable', 'exists:sms_bundles,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'amount' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'method' => ['required', Rule::in(array_keys(PaymentService::OFFLINE_METHODS))],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'payer_phone' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $bundle = !empty($data['bundle_id']) ? SmsBundle::find($data['bundle_id']) : null;

        try {
            $transaction = $payments->recordOffline(
                purpose: 'sms',
                landlord: $landlord,
                amount: (float) $data['amount'],
                method: $data['method'],
                receivedAt: \Carbon\Carbon::parse($data['received_on']),
                context: array_filter([
                    'bundle_id' => $bundle?->id,
                    'bundle_name' => $bundle?->name,
                    'quantity' => (int) $data['quantity'],
                ], fn ($v) => $v !== null),
                reference: $data['reference'] ?? null,
                payerPhone: $data['payer_phone'] ?? null,
                note: $data['note'] ?? null,
                payable: $landlord,
                recordedBy: $request->user('staff'),
            );
        } catch (PaymentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Sale {$transaction->reference} recorded: ".number_format($data['quantity']).' SMS added. New balance: '.number_format($credits->balance($landlord)).' SMS.');
    }
}
