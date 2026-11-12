<?php

namespace App\Http\Controllers;

use App\Mail\NoticeMail;
use App\Models\Landlord;
use App\Models\Liquidation;
use App\Models\PayoutAccount;
use App\Models\StaffUser;
use App\Models\User;
use App\Payments\Msisdn;
use App\Services\ActionCodes;
use App\Services\CurrentLandlord;
use App\Services\LiquidationException;
use App\Services\Liquidations;
use App\Services\PayoutFees;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * A landlord's payouts: the rent Primora has collected for them, where it can
 * be paid (verified payout accounts), and requests to be paid.
 */
class PayoutController extends Controller
{
    /** Session key for an account waiting for its confirmation code. */
    public const PENDING_ACCOUNT = 'payouts.pending_account';

    /** Session key for a payout request waiting for its confirmation code. */
    public const PENDING_PAYOUT = 'payouts.pending_payout';

    public function __construct(protected Liquidations $liquidations, protected ActionCodes $codes)
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
            'pendingAccount' => $this->isStaff() ? null : $this->pendingAccount(),
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

        $account = PayoutAccount::find($data['payout_account_id']);
        if (!$account) {
            return back()->withInput()->withErrors(['request' => 'Choose one of your payout accounts.']);
        }

        // Check it now, so a code is only sent for a request that can go through.
        try {
            $quote = $this->liquidations->check(Landlord::findOrFail(CurrentLandlord::id()), $account, (float) $data['amount']);
        } catch (LiquidationException $e) {
            return back()->withInput()->withErrors(['request' => $e->getMessage()]);
        }

        // Second factor: nothing is requested until the user enters the code sent to their email / phone.
        $amount = round((float) $data['amount']);
        if ($error = $this->codes->send($request->user(), ActionCodes::PAYOUT_REQUEST, 'request a payout of UGX '.number_format($amount).' to '.$account->summary(), $request->ip())) {
            return back()->withInput()->withErrors(['request' => $error]);
        }

        $request->session()->put(self::PENDING_PAYOUT, [
            'landlord_id' => CurrentLandlord::id(),
            'user_id' => $request->user()->id,
            'payout_account_id' => $account->id,
            'amount' => $amount,
            'fee' => $quote['fee'],
            'net' => $quote['net'],
            'note' => $data['note'] ?? null,
            'summary' => $account->summary(),
            'expires_at' => now()->addMinutes(ActionCodes::CODE_MINUTES)->timestamp,
        ]);

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

        if (!$this->codes->verify($request->user(), ActionCodes::PAYOUT_REQUEST, (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is wrong or has expired. Check it, or send a new code.']);
        }

        $request->session()->forget(self::PENDING_PAYOUT);

        $account = PayoutAccount::find($pending['payout_account_id']);
        try {
            if (!$account) {
                throw new LiquidationException('Choose a verified payout account.');
            }
            $liquidation = $this->liquidations->request(Landlord::findOrFail(CurrentLandlord::id()), $account, (float) $pending['amount'], $request->user(), $pending['note']);
        } catch (LiquidationException $e) {
            return redirect()->route('payouts.index')->withErrors(['request' => $e->getMessage()]);
        }

        return redirect()->route('payouts.index')->with('payout_received', $liquidation->id);
    }

    public function resendCode(Request $request)
    {
        abort_if($this->isStaff(), 403);

        $pending = $this->pending(self::PENDING_PAYOUT);
        if (!$pending) {
            return redirect()->route('payouts.index')->with('error', 'That request has expired. Please request the payout again.');
        }

        if ($error = $this->codes->send($request->user(), ActionCodes::PAYOUT_REQUEST, 'request a payout of UGX '.number_format($pending['amount']).' to '.$pending['summary'], $request->ip())) {
            return back()->with('error', $error);
        }

        $request->session()->put(self::PENDING_PAYOUT.'.expires_at', now()->addMinutes(ActionCodes::CODE_MINUTES)->timestamp);

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

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(PayoutAccount::TYPES))],
            'account_name' => ['required', 'string', 'max:150'],
            'msisdn' => ['required_if:type,mobile_money', 'nullable', 'string', 'max:20'],
            'bank_name' => ['required_if:type,bank', 'nullable', 'string', 'max:100'],
            'bank_branch' => ['nullable', 'string', 'max:100'],
            'account_number' => ['required_if:type,bank', 'nullable', 'string', 'max:50', 'regex:/^[0-9A-Za-z\- ]+$/'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'current_password:web'],
        ], [
            'password.current_password' => 'That password is not correct.',
            'msisdn.required_if' => 'Enter the mobile money number.',
            'bank_name.required_if' => 'Enter the bank name.',
            'account_number.required_if' => 'Enter the account number.',
        ]);

        $fields = ['type' => $data['type'], 'account_name' => trim($data['account_name'])];
        if ($data['type'] === 'mobile_money') {
            $msisdn = Msisdn::normalize($data['msisdn']);
            if (!$msisdn) {
                return back()->withInput()->withErrors(['msisdn' => 'Enter a Ugandan MTN or Airtel number, e.g. 0772 123 456.']);
            }
            $fields['msisdn'] = $msisdn;
        } else {
            $fields += [
                'bank_name' => trim($data['bank_name']),
                'bank_branch' => !empty($data['bank_branch']) ? trim($data['bank_branch']) : null,
                'account_number' => preg_replace('/\s+/', '', $data['account_number']),
                'swift_code' => !empty($data['swift_code']) ? strtoupper(trim($data['swift_code'])) : null,
            ];
        }

        if ($fields['type'] === 'mobile_money' && PayoutAccount::mobileMoneyInUse() >= PayoutAccount::mobileMoneyLimit()) {
            return back()->withInput()->withErrors(['msisdn' => $this->limitMessage()]);
        }

        // Second factor: nothing is saved until the user enters the code sent to their email / phone.
        $summary = (new PayoutAccount($fields))->summary();
        if ($error = $this->codes->send($request->user(), ActionCodes::PAYOUT_ACCOUNT, "add payout account {$summary}", $request->ip())) {
            return back()->withInput()->with('error', $error);
        }

        $request->session()->put(self::PENDING_ACCOUNT, [
            'landlord_id' => CurrentLandlord::id(),
            'user_id' => $request->user()->id,
            'fields' => $fields,
            'summary' => $summary,
            'expires_at' => now()->addMinutes(ActionCodes::CODE_MINUTES)->timestamp,
        ]);

        return redirect()->route('payouts.index')->with('success', 'We sent a confirmation code to '.ActionCodes::destinations($request->user()).'. Enter it to finish adding the account.');
    }

    /** Step 2: the code proves it's really the account holder; then the account is added. */
    public function confirmAccount(Request $request)
    {
        abort_if($this->isStaff(), 403);

        $pending = $this->pendingAccount();
        if (!$pending) {
            return redirect()->route('payouts.index')->with('error', 'That request has expired. Please add the account again.');
        }

        $request->validate(['code' => ['required', 'string', 'max:12']], ['code.required' => 'Enter the code we sent you.']);

        if (!$this->codes->verify($request->user(), ActionCodes::PAYOUT_ACCOUNT, (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is wrong or has expired. Check it, or send a new code.']);
        }

        $fields = $pending['fields'];

        // Lock the landlord row so two quick submissions can't both slip under the limit.
        $account = DB::transaction(function () use ($fields, $request) {
            if ($fields['type'] === 'mobile_money') {
                Landlord::whereKey(CurrentLandlord::id())->lockForUpdate()->first();
                if (PayoutAccount::mobileMoneyInUse() >= PayoutAccount::mobileMoneyLimit()) {
                    return null;
                }
            }

            return PayoutAccount::create($fields + ['status' => 'pending', 'created_by_user_id' => $request->user()->id]);
        });

        $request->session()->forget(self::PENDING_ACCOUNT);

        if (!$account) {
            return redirect()->route('payouts.index')->with('error', $this->limitMessage());
        }

        $this->announceNewAccount($account->load('landlord'), $request->user());

        return redirect()->route('payouts.index')->with('success', 'Payout account added. Primora will verify it before it can be used.');
    }

    public function resendAccountCode(Request $request)
    {
        abort_if($this->isStaff(), 403);

        $pending = $this->pendingAccount();
        if (!$pending) {
            return redirect()->route('payouts.index')->with('error', 'That request has expired. Please add the account again.');
        }

        if ($error = $this->codes->send($request->user(), ActionCodes::PAYOUT_ACCOUNT, "add payout account {$pending['summary']}", $request->ip())) {
            return back()->with('error', $error);
        }

        $request->session()->put(self::PENDING_ACCOUNT.'.expires_at', now()->addMinutes(ActionCodes::CODE_MINUTES)->timestamp);

        return back()->with('success', 'A new code was sent to '.ActionCodes::destinations($request->user()).'.');
    }

    public function cancelPendingAccount(Request $request)
    {
        $request->session()->forget(self::PENDING_ACCOUNT);

        return redirect()->route('payouts.index');
    }

    protected function pendingAccount(): ?array
    {
        return $this->pending(self::PENDING_ACCOUNT);
    }

    /** What's waiting for a code under $key, if it's this user's, for this landlord, and not expired. */
    protected function pending(string $key): ?array
    {
        $pending = session($key);

        if (!is_array($pending) || ($pending['user_id'] ?? null) !== auth()->id()
            || ($pending['landlord_id'] ?? null) !== CurrentLandlord::id() || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        return $pending;
    }

    protected function limitMessage(): string
    {
        $limit = PayoutAccount::mobileMoneyLimit();

        return "You can have up to {$limit} mobile money ".($limit === 1 ? 'number' : 'numbers').'. Remove one before adding another.';
    }

    public function destroyAccount(PayoutAccount $payoutAccount)
    {
        abort_if($this->isStaff(), 403);

        $payoutAccount->update(['status' => 'disabled']);

        return back()->with('success', 'Payout account removed.');
    }

    protected function isStaff(): bool
    {
        return auth()->user() instanceof StaffUser;
    }

    /** Tell the landlord's admins (in case it wasn't them) and the staff who verify accounts. */
    protected function announceNewAccount(PayoutAccount $account, User $by): void
    {
        $landlord = $account->landlord;
        $admins = User::where('landlord_id', $landlord->id)->where('role', 'admin')->where('is_active', true)->pluck('email')
            ->push($landlord->contact_email)->filter()->unique()->values()->all();
        $verifiers = StaffUser::where('is_active', true)->get()->filter(fn (StaffUser $s) => $s->hasPermission('liquidations.approve'))->pluck('email')->all();

        try {
            if ($admins) {
                Mail::to($admins)->send(new NoticeMail(
                    subject: 'A payout account was added to your '.config('app.name').' account',
                    heading: 'New payout account added',
                    lines: [
                        "{$by->name} added a payout account: {$account->summary()}.",
                        'Primora will verify it before any money can be sent to it.',
                        "If you didn't expect this, remove it from Payouts and change your password straight away.",
                    ],
                    actionText: 'Review payout accounts',
                    actionUrl: route('payouts.index'),
                ));
            }
            if ($verifiers) {
                Mail::to($verifiers)->send(new NoticeMail(
                    subject: "Payout account to verify for {$landlord->name}",
                    heading: 'A payout account needs verifying',
                    lines: ["{$landlord->name} added: {$account->summary()}.", 'Confirm the account belongs to the landlord before verifying it.'],
                    actionText: 'Verify accounts',
                    actionUrl: route('staff.payout-accounts.index'),
                ));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
