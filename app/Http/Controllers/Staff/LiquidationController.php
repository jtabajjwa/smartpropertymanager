<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Mail\NoticeMail;
use App\Models\Liquidation;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\LiquidationException;
use App\Services\Liquidations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Staff side of landlord payouts. Ids rather than route-model binding:
 * these records are landlord-scoped, and staff see every landlord's.
 */
class LiquidationController extends Controller
{
    /** Queue tabs: key => [label, statuses] */
    public const TABS = [
        'to_approve' => ['To approve', [Liquidation::PENDING]],
        'to_execute' => ['To execute', [Liquidation::APPROVED, Liquidation::FAILED]],
        'processing' => ['Sending', [Liquidation::PROCESSING]],
        'completed' => ['Completed', [Liquidation::COMPLETED]],
        'closed' => ['Rejected / cancelled', [Liquidation::REJECTED, Liquidation::CANCELLED]],
    ];

    public function __construct(protected Liquidations $liquidations)
    {
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->input('tab'), self::TABS) ? $request->input('tab') : 'to_approve';

        $counts = Liquidation::withoutGlobalScopes()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('staff.liquidations.index', [
            'tab' => $tab,
            'counts' => collect(self::TABS)->map(fn ($t) => collect($t[1])->sum(fn ($s) => $counts[$s] ?? 0)),
            'payouts' => Liquidation::withoutGlobalScopes()->with(['landlord', 'reviewedBy', 'executedBy'])
                ->whereIn('status', self::TABS[$tab][1])->latest('id')->paginate(25)->withQueryString(),
            'accountsToVerify' => PayoutAccount::withoutGlobalScopes()->where('status', 'pending')->count(),
            'held' => (float) app(\App\Accounting\Ledger::class)->balance('landlord_payable'),
        ]);
    }

    public function show(int $id)
    {
        $l = Liquidation::withoutGlobalScopes()->with(['landlord', 'requestedBy', 'reviewedBy', 'executedBy', 'logs'])->findOrFail($id);

        return view('staff.liquidations.show', [
            'l' => $l,
            'owed' => $this->liquidations->owed($l->landlord_id),
            'availableExcludingThis' => $this->liquidations->available($l->landlord_id, $l->id),
            'account' => $l->payout_account_id ? PayoutAccount::withoutGlobalScopes()->find($l->payout_account_id) : null,
            'staff' => auth('staff')->user(),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return $this->attempt(fn () => $this->liquidations->approve($this->find($id), $request->user('staff'), $data['note'] ?? null),
            'Approved. A different staff member must now execute it.');
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']], ['reason.required' => 'Give the landlord a reason.']);

        return $this->attempt(fn () => $this->liquidations->reject($this->find($id), $request->user('staff'), $data['reason']),
            'Rejected. The landlord has been emailed and the amount is available to them again.');
    }

    public function send(Request $request, int $id)
    {
        $request->validate(['transfer_type' => ['nullable', 'in:EFT,RTGS']]);

        try {
            $l = $this->liquidations->sendViaYo($this->find($id), $request->user('staff'), $request->input('transfer_type'));
        } catch (LiquidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($l->status === Liquidation::FAILED ? 'error' : 'success', match ($l->status) {
            Liquidation::COMPLETED => 'Paid. The landlord has been emailed.',
            Liquidation::PROCESSING => $l->isMobileMoney()
                ? 'Sent to Yo! Payments. Waiting for confirmation; the status updates automatically.'
                : 'Bank transfer submitted to Yo! Payments. It completes when Yo! captures it; the status updates automatically.',
            Liquidation::FAILED => 'The payout failed: '.($l->status_message ?: 'no reason given').' You can retry or reject it.',
            default => 'Sent to Yo! Payments. Waiting for confirmation; the status updates automatically.',
        });
    }

    public function completeBank(Request $request, int $id)
    {
        $data = $request->validate([
            'bank_reference' => ['required', 'string', 'max:100'],
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        return $this->attempt(fn () => $this->liquidations->completeBank($this->find($id), $request->user('staff'), $data['bank_reference'], $data['transfer_date']),
            'Bank payout recorded as completed. The landlord has been emailed.');
    }

    public function check(int $id)
    {
        $l = $this->liquidations->refresh($this->find($id));

        return back()->with('success', 'Status: '.$l->staffStatus().($l->status_message ? " · {$l->status_message}" : ''));
    }

    // --- Payout accounts ----------------------------------------------------------

    public function accounts(Request $request)
    {
        $status = in_array($request->input('status'), ['pending', 'verified', 'rejected', 'disabled'], true) ? $request->input('status') : 'pending';

        return view('staff.liquidations.accounts', [
            'status' => $status,
            'accounts' => PayoutAccount::withoutGlobalScopes()->with(['landlord', 'createdBy', 'reviewedBy'])->where('status', $status)->latest()->paginate(25)->withQueryString(),
            // Bank details used for earlier Yo! registrations, to prefill the next one at the same bank.
            'bankDefaults' => PayoutAccount::withoutGlobalScopes()->whereNotNull('yo_bank_info')->latest('yo_registered_at')->get()
                ->unique(fn ($a) => mb_strtolower(trim((string) $a->bank_name)))->mapWithKeys(fn ($a) => [mb_strtolower(trim((string) $a->bank_name)) => $a->yo_bank_info]),
            'canRegisterWithYo' => ($g = app(\App\Payments\PaymentGatewayManager::class)->gateway()) instanceof \App\Payments\Contracts\PayoutGateway && $g->canSendPayouts(),
            'counts' => PayoutAccount::withoutGlobalScopes()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function verifyAccount(Request $request, int $id)
    {
        $account = PayoutAccount::withoutGlobalScopes()->with('landlord')->findOrFail($id);
        abort_unless($account->status === 'pending', 422, 'Only accounts awaiting verification can be verified.');

        $account->update(['status' => 'verified', 'reviewed_by_staff_user_id' => $request->user('staff')->id, 'reviewed_at' => now(), 'review_note' => null]);
        $this->tellLandlord($account, 'verified');

        return back()->with('success', 'Payout account verified.');
    }

    public function rejectAccount(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);
        $account = PayoutAccount::withoutGlobalScopes()->with('landlord')->findOrFail($id);
        abort_unless($account->status === 'pending', 422);

        $account->update(['status' => 'rejected', 'reviewed_by_staff_user_id' => $request->user('staff')->id, 'reviewed_at' => now(), 'review_note' => $data['reason']]);
        $this->tellLandlord($account, 'rejected');

        return back()->with('success', 'Payout account rejected.');
    }

    /** Register a verified bank account on Primora's Yo! account (§16), so Yo! can pay it. */
    public function registerWithYo(Request $request, int $id)
    {
        $account = PayoutAccount::withoutGlobalScopes()->findOrFail($id);
        abort_unless($account->type === 'bank' && $account->status === 'verified', 422, 'Only verified bank accounts can be registered with Yo!.');
        if ($account->yo_bank_identifier) {
            return back()->with('error', 'This account is already registered with Yo!.');
        }

        $bank = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address1' => ['required', 'string', 'max:150'],
            'address2' => ['required', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['required', 'string', 'max:80'],
            'swift' => ['nullable', 'string', 'max:20'],
        ], ['name.required' => "Enter the bank's full legal name, e.g. Stanbic Bank Uganda Limited."]);

        $gateway = app(\App\Payments\PaymentGatewayManager::class)->gateway();
        if (!$gateway instanceof \App\Payments\Contracts\PayoutGateway) {
            return back()->with('error', 'The payment gateway in use cannot register bank accounts.');
        }

        try {
            $identifier = $gateway->registerBankAccount($account, $bank);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $account->update(['yo_bank_identifier' => $identifier, 'yo_bank_info' => $bank, 'yo_registered_at' => now()]);

        return back()->with('success', "Registered with Yo! Payments (identifier {$identifier}). Bank payouts to it can now go through Yo!.");
    }

    /** For an account registered in Yo!'s web portal: record its API Bank Identifier. */
    public function setYoIdentifier(Request $request, int $id)
    {
        $account = PayoutAccount::withoutGlobalScopes()->findOrFail($id);
        abort_unless($account->type === 'bank' && $account->status === 'verified', 422);

        $data = $request->validate(['yo_bank_identifier' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-_.]+$/']],
            ['yo_bank_identifier.required' => "Enter the account's API Bank Identifier from the Yo! portal."]);

        $account->update(['yo_bank_identifier' => $data['yo_bank_identifier'], 'yo_registered_at' => now()]);

        return back()->with('success', 'Yo! bank identifier saved.');
    }

    // ------------------------------------------------------------------------------

    protected function find(int $id): Liquidation
    {
        return Liquidation::withoutGlobalScopes()->with('landlord')->findOrFail($id);
    }

    protected function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (LiquidationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    protected function tellLandlord(PayoutAccount $account, string $outcome): void
    {
        $to = User::where('landlord_id', $account->landlord_id)->where('role', 'admin')->where('is_active', true)->pluck('email')
            ->push($account->landlord?->contact_email)->filter()->unique()->values()->all();
        if (!$to) {
            return;
        }

        try {
            Mail::to($to)->send(new NoticeMail(
                subject: $outcome === 'verified' ? 'Your payout account has been verified' : 'Your payout account could not be verified',
                heading: $outcome === 'verified' ? 'Payout account verified' : 'Payout account not verified',
                lines: array_values(array_filter([
                    $account->summary(),
                    $outcome === 'verified' ? 'You can now request payouts to this account.' : 'Reason: '.$account->review_note,
                ])),
                actionText: 'Open Payouts',
                actionUrl: route('payouts.index'),
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
