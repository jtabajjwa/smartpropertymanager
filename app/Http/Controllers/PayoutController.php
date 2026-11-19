<?php

namespace App\Http\Controllers;

use App\Models\Landlord;
use App\Models\Liquidation;
use App\Models\PayoutAccount;
use App\Models\StaffUser;
use App\Services\ActionCodes;
use App\Services\CurrentLandlord;
use App\Services\LiquidationException;
use App\Services\Liquidations;
use App\Services\PayoutFees;
use App\Services\PayoutSteps;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A landlord's payouts: the rent Primora has collected for them, where it can
 * be paid (verified payout accounts), and requests to be paid. Adding an
 * account and requesting a payout are confirmed with a code (PayoutSteps,
 * shared with the mobile app); the step waiting for its code is kept in the session.
 */
class PayoutController extends Controller
{
    /** Session key for an account waiting for its confirmation code. */
    public const PENDING_ACCOUNT = 'payouts.pending_account';

    /** Session key for a payout request waiting for its confirmation code. */
    public const PENDING_PAYOUT = 'payouts.pending_payout';

    public function __construct(protected Liquidations $liquidations, protected PayoutSteps $steps)
    {
    }

    public function index(PayoutFees $fees)
    {
        $landlord = Landlord::findOrFail(CurrentLandlord::id());

        return view('payouts.index', [
            'landlord' => $landlord,
            'owed' => $this->liquidations->owed($landlord->id),
            'reserved' => $this->liquidations->reserved($landlord->id),
            'available' => $this->liquidations->available($landlord->id),
            'paidOut' => (float) Liquidation::where('status', Liquidation::COMPLETED)->sum('net_amount'),
            'accounts' => PayoutAccount::where('status', '!=', 'disabled')->latest()->get(),
            'payouts' => Liquidation::latest('id')->paginate(15),
            'feeBands' => $fees->forScript(),
            'canRequest' => !$this->isStaff(),
            'min' => Liquidations::MIN_AMOUNT,
            'mobileMax' => Liquidations::MOBILE_MONEY_MAX,
            'mobileLimit' => PayoutAccount::mobileMoneyLimit(),
            'mobileInUse' => PayoutAccount::mobileMoneyInUse(),
            'pendingAccount' => $this->isStaff() ? null : $this->pending(self::PENDING_ACCOUNT),
            'pendingPayout' => $this->isStaff() ? null : $this->pending(self::PENDING_PAYOUT),
            'received' => session('payout_received') ? Liquidation::find(session('payout_received')) : null,
            'codeDestinations' => $this->isStaff() ? '' : ActionCodes::destinations(auth()->user()),
        ]);
    }

    public function store(Request $request)
    {
        abort_if($this->isStaff(), 403, 'Primora staff cannot request payouts for a landlord.');

        $data = $request->validate([
            'payout_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:'.Liquidations::MIN_AMOUNT],
            'note' => ['nullable', 'string', 'max:300'],
            'password' => ['required', 'current_password:web'],
        ], [
            'password.current_password' => 'That password is not correct.',
            'amount.min' => 'The smallest payout is UGX '.number_format(Liquidations::MIN_AMOUNT).'.',
        ]);

        try {
            $pending = $this->steps->startPayout($request->user(), Landlord::findOrFail(CurrentLandlord::id()),
                (int) $data['payout_account_id'], (float) $data['amount'], $data['note'] ?? null, $request->ip());
        } catch (LiquidationException $e) {
            return back()->withInput()->withErrors(['request' => $e->getMessage()]);
        }

        $request->session()->put(self::PENDING_PAYOUT, $pending);

        return redirect()->route('payouts.index');
    }

    /** Step 2: the code proves it's really the account holder; then the request goes to Primora. */
    public function confirm(Request $request)
    {
        abort_if($this->isStaff(), 403);

        $pending = $this->pending(self::PENDING_PAYOUT);
        if (!$pending) {
            return redirect()->route('payouts.index')->with('error', 'That request has expired. Please request the payout again.');
        }

        $request->validate(['code' => ['required', 'string', 'max:12']], ['code.required' => 'Enter the code we sent you.']);

        try {
            $liquidation = $this->steps->confirmPayout($request->user(), Landlord::findOrFail(CurrentLandlord::id()), $pending, (string) $request->input('code'));
        } catch (LiquidationException $e) {
            $request->session()->forget(self::PENDING_PAYOUT);

            return redirect()->route('payouts.index')->withErrors(['request' => $e->getMessage()]);
        }
        $request->session()->forget(self::PENDING_PAYOUT);

        return redirect()->route('payouts.index')->with('payout_received', $liquidation->id);
    }

    public function resendCode(Request $request)
    {
        abort_if($this->isStaff(), 403);

        $pending = $this->pending(self::PENDING_PAYOUT);
        if (!$pending) {
            return redirect()->route('payouts.index')->with('error', 'That request has expired. Please request the payout again.');
        }

        try {
            $request->session()->put(self::PENDING_PAYOUT, $this->steps->resendPayoutCode($request->user(), $pending, $request->ip()));
        } catch (LiquidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'A new code was sent to '.ActionCodes::destinations($request->user()).'.');
    }

    public function cancelPending(Request $request)
    {
        $request->session()->forget(self::PENDING_PAYOUT);

        return redirect()->route('payouts.index');
    }

    public function cancel(Liquidation $liquidation)
    {
        abort_if($this->isStaff(), 403);

        try {
            $this->liquidations->cancel($liquidation);
        } catch (LiquidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Payout request {$liquidation->reference} cancelled.");
    }

    public function storeAccount(Request $request)
    {
        abort_if($this->isStaff(), 403, 'Primora staff cannot add payout accounts for a landlord.');

        $data = $request->validate(self::accountRules(), self::accountMessages());

        try {
            $pending = $this->steps->startAccount($request->user(), Landlord::findOrFail(CurrentLandlord::id()), $data, $request->ip());
        } catch (LiquidationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $request->session()->put(self::PENDING_ACCOUNT, $pending);

        return redirect()->route('payouts.index')->with('success', 'We sent a confirmation code to '.ActionCodes::destinations($request->user()).'. Enter it to finish adding the account.');
    }

    /** Step 2: the code proves it's really the account holder; then the account is added. */
    public function confirmAccount(Request $request)
    {
        abort_if($this->isStaff(), 403);

        $pending = $this->pending(self::PENDING_ACCOUNT);
        if (!$pending) {
            return redirect()->route('payouts.index')->with('error', 'That request has expired. Please add the account again.');
        }

        $request->validate(['code' => ['required', 'string', 'max:12']], ['code.required' => 'Enter the code we sent you.']);

        try {
            $this->steps->confirmAccount($request->user(), Landlord::findOrFail(CurrentLandlord::id()), $pending, (string) $request->input('code'));
        } catch (LiquidationException $e) {
            $request->session()->forget(self::PENDING_ACCOUNT);

            return redirect()->route('payouts.index')->with('error', $e->getMessage());
        }
        $request->session()->forget(self::PENDING_ACCOUNT);

        return redirect()->route('payouts.index')->with('success', 'Payout account added. Primora will verify it before it can be used.');
    }

    public function resendAccountCode(Request $request)
    {
        abort_if($this->isStaff(), 403);

        $pending = $this->pending(self::PENDING_ACCOUNT);
        if (!$pending) {
            return redirect()->route('payouts.index')->with('error', 'That request has expired. Please add the account again.');
        }

        try {
            $request->session()->put(self::PENDING_ACCOUNT, $this->steps->resendAccountCode($request->user(), $pending, $request->ip()));
        } catch (LiquidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'A new code was sent to '.ActionCodes::destinations($request->user()).'.');
    }

    public function cancelPendingAccount(Request $request)
    {
        $request->session()->forget(self::PENDING_ACCOUNT);

        return redirect()->route('payouts.index');
    }

    public function destroyAccount(PayoutAccount $payoutAccount)
    {
        abort_if($this->isStaff(), 403);

        $payoutAccount->update(['status' => 'disabled']);

        return back()->with('success', 'Payout account removed.');
    }

    /** The fields of a new payout account (shared with the mobile app's API). */
    public static function accountRules(string $guard = 'web'): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(PayoutAccount::TYPES))],
            'account_name' => ['required', 'string', 'max:150'],
            'msisdn' => ['required_if:type,mobile_money', 'nullable', 'string', 'max:20'],
            'bank_name' => ['required_if:type,bank', 'nullable', 'string', 'max:100'],
            'bank_branch' => ['nullable', 'string', 'max:100'],
            'account_number' => ['required_if:type,bank', 'nullable', 'string', 'max:50', 'regex:/^[0-9A-Za-z\- ]+$/'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'current_password:'.$guard],
        ];
    }

    public static function accountMessages(): array
    {
        return [
            'password.current_password' => 'That password is not correct.',
            'msisdn.required_if' => 'Enter the mobile money number.',
            'bank_name.required_if' => 'Enter the bank name.',
            'account_number.required_if' => 'Enter the account number.',
        ];
    }

    /** What's waiting for a code under $key, if it's this user's, for this landlord, and not expired. */
    protected function pending(string $key): ?array
    {
        $pending = session($key);

        return auth()->user() instanceof \App\Models\User && PayoutSteps::stillValid($pending, auth()->user(), (int) CurrentLandlord::id()) ? $pending : null;
    }

    protected function isStaff(): bool
    {
        return auth()->user() instanceof StaffUser;
    }
}
