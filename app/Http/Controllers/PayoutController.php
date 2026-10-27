<?php

namespace App\Http\Controllers;

use App\Mail\NoticeMail;
use App\Models\Landlord;
use App\Models\Liquidation;
use App\Models\PayoutAccount;
use App\Models\StaffUser;
use App\Models\User;
use App\Payments\Msisdn;
use App\Services\CurrentLandlord;
use App\Services\LiquidationException;
use App\Services\Liquidations;
use App\Services\PayoutFees;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * A landlord's payouts: the rent Primora has collected for them, where it can
 * be paid (verified payout accounts), and requests to be paid.
 */
class PayoutController extends Controller
{
    public function __construct(protected Liquidations $liquidations)
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
            return back()->withInput()->with('error', 'Choose one of your payout accounts.');
        }

        try {
            $liquidation = $this->liquidations->request(Landlord::findOrFail(CurrentLandlord::id()), $account, (float) $data['amount'], $request->user(), $data['note'] ?? null);
        } catch (LiquidationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('payouts.index')->with('success', "Payout request {$liquidation->reference} sent to Primora for approval.");
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
                'bank_branch' => $data['bank_branch'] ? trim($data['bank_branch']) : null,
                'account_number' => preg_replace('/\s+/', '', $data['account_number']),
                'swift_code' => $data['swift_code'] ? strtoupper(trim($data['swift_code'])) : null,
            ];
        }

        $account = PayoutAccount::create($fields + ['status' => 'pending', 'created_by_user_id' => $request->user()->id]);

        $this->announceNewAccount($account->load('landlord'), $request->user());

        return back()->with('success', 'Payout account added. Primora will verify it before it can be used.');
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
