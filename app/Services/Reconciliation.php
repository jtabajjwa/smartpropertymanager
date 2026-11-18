<?php

namespace App\Services;

use App\Accounting\Ledger;
use App\Mail\NoticeMail;
use App\Models\BalanceCheck;
use App\Models\LedgerAccount;
use App\Models\Liquidation;
use App\Models\PaymentTransaction;
use App\Models\StaffUser;
use App\Payments\Contracts\BalanceGateway;
use App\Payments\PaymentGatewayManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Automatic reconciliation with the partners holding Primora's money.
 *
 *  - Balance check: what Yo! says the pool holds, against the books' Yo!
 *    Payments pool, allowing for payouts Yo! has taken but the books will
 *    only record once they complete:
 *        difference = Yo! balance − (books − payouts being sent)
 *  - Safeguarding: all money held (Yo! as reported, bank, wallet and cash
 *    from the books) must cover what is owed to landlords.
 *  - Follow-ups: payouts stuck in sending, and collections the automatic
 *    status checks have given up on.
 *
 * Staff who record in the books are emailed when a problem appears, while it
 * lasts (at most daily), and when it clears.
 */
class Reconciliation
{
    /** Ledger accounts that hold actual money (others in "assets" may not). */
    public const MONEY_ACCOUNTS = ['yo_pool', 'bank', 'momo_wallet', 'cash'];

    public function __construct(protected Ledger $ledger, protected PaymentGatewayManager $gateways)
    {
    }

    public function gatewayName(): string
    {
        return (string) (config('payments.balance_check.gateway') ?: config('payments.default'));
    }

    /** Compare, save the result, and email staff if something needs attention. */
    public function check(?StaffUser $by = null): BalanceCheck
    {
        $pool = LedgerAccount::key('yo_pool');
        $book = $this->ledger->balance($pool);
        $inFlight = $this->payoutsInFlight();
        $otherMoney = collect(self::MONEY_ACCOUNTS)->reject(fn ($k) => $k === 'yo_pool')
            ->sum(fn ($k) => LedgerAccount::where('system_key', $k)->exists() ? $this->ledger->balance($k) : 0.0);
        $tolerance = (float) config('payments.balance_check.tolerance', 0);

        $data = [
            'ledger_account_id' => $pool->id,
            'gateway' => $this->gatewayName(),
            'checked_at' => now(),
            'book_balance' => $book,
            'in_flight' => $inFlight,
            'landlord_funds' => $this->ledger->balance('landlord_payable'),
            'partner_funds' => LedgerAccount::where('system_key', 'partner_payable')->exists() ? max(0.0, $this->ledger->balance('partner_payable')) : 0.0,
            'checked_by_staff_user_id' => $by?->id,
        ];

        try {
            $balance = $this->partnerBalance();
            $difference = round($balance['total'] - ($book - $inFlight), 2);
            $data += [
                'status' => abs($difference) <= $tolerance ? BalanceCheck::MATCHED : BalanceCheck::DIFFERENCE,
                'partner_balance' => $balance['total'],
                'wallets' => $balance['wallets'],
                'difference' => $difference,
                'money_held' => round($balance['total'] + $otherMoney, 2),
            ];
        } catch (\RuntimeException $e) {
            // Safeguarding can still be checked from the books alone.
            $data += [
                'status' => BalanceCheck::FAILED,
                'message' => mb_substr($e->getMessage(), 0, 500),
                'money_held' => round($book - $inFlight + $otherMoney, 2),
            ];
        }

        $check = BalanceCheck::create($data);
        $check->update(['problems' => $this->problems($check)]);
        $this->alert($check);

        return $check;
    }

    /** Payouts Yo! is sending: taken from the pool, not yet in the books (they post on completion). */
    public function payoutsInFlight(): float
    {
        return round((float) Liquidation::withoutGlobalScopes()->where('status', Liquidation::PROCESSING)->whereNotNull('gateway')
            ->selectRaw('COALESCE(SUM(net_amount + fee), 0) as total')->value('total'), 2);
    }

    /** Collections still waiting for an answer: a common reason Yo! briefly holds more than the books. */
    public function openCollections(): Collection
    {
        return PaymentTransaction::open()->where('gateway', $this->gatewayName())->latest()->get();
    }

    /**
     * Items that need a person: payouts stuck in sending, and collections the
     * automatic status checks have stopped asking about.
     *
     * @return array{payouts: Collection, collections: Collection}
     */
    public function followUps(): array
    {
        $staleMinutes = (int) config('payments.balance_check.stale_payout_minutes', 60);
        $giveUpHours = (int) config('payments.reconcile.give_up_after_hours', 72);

        return [
            'payouts' => Liquidation::withoutGlobalScopes()->with('landlord')->where('status', Liquidation::PROCESSING)
                ->where(fn ($q) => $q->where('sent_at', '<=', now()->subMinutes($staleMinutes))->orWhereNull('sent_at'))
                ->oldest('sent_at')->get(),
            'collections' => PaymentTransaction::open()->with('landlord')->where('created_at', '<=', now()->subHours($giveUpHours))->oldest()->get(),
        ];
    }

    /** @return array{total: float, wallets: array<string, float>} */
    protected function partnerBalance(): array
    {
        try {
            $gateway = $this->gateways->gateway($this->gatewayName());
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage());
        }
        if (!$gateway instanceof BalanceGateway) {
            throw new \RuntimeException("The {$gateway->name()} gateway can't report a balance.");
        }
        if (!$gateway->isConfigured()) {
            throw new \RuntimeException("The {$gateway->name()} gateway is not set up (credentials missing).");
        }

        return $gateway->accountBalance();
    }

    /** What's wrong now, keyed by kind, in words for staff. */
    protected function problems(BalanceCheck $check): array
    {
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $problems = [];
        $tolerance = (float) config('payments.balance_check.tolerance', 0);

        if (($cover = $check->cover()) !== null && $cover < -$tolerance) {
            $problems['shortfall'] = 'Money held ('.$ugx($check->money_held).') is '.$ugx(-$cover).' less than what is owed to landlords ('.$ugx($check->landlord_funds).')'
                .($check->partner_funds > 0 ? ' and partners ('.$ugx($check->partner_funds).')' : '').'.'
                .($check->status === BalanceCheck::FAILED ? ' (Yo! balance from the books: the check with Yo! failed.)' : '');
        }

        // A difference counts once it has lasted several checks in a row.
        $needed = max(1, (int) config('payments.balance_check.alert_after_checks', 2));
        $recent = BalanceCheck::where('ledger_account_id', $check->ledger_account_id)->latest('checked_at')->latest('id')->limit($needed)->pluck('status');
        if ($check->status === BalanceCheck::DIFFERENCE && $recent->count() === $needed && $recent->every(fn ($s) => $s === BalanceCheck::DIFFERENCE)) {
            $problems['difference'] = 'Yo! holds '.$ugx($check->partner_balance).'; the books expect '.$ugx($check->book_balance - $check->in_flight)
                .' (difference '.($check->difference > 0 ? '+' : '−').number_format(abs($check->difference)).').';
        }

        $lastThree = BalanceCheck::where('ledger_account_id', $check->ledger_account_id)->latest('checked_at')->latest('id')->limit(3)->pluck('status');
        if ($lastThree->count() === 3 && $lastThree->every(fn ($s) => $s === BalanceCheck::FAILED)) {
            $problems['failing'] = 'The balance check with Yo! has failed 3 times in a row: '.$check->message;
        }

        return $problems;
    }

    /** Email on a new or changed problem, daily while it lasts, and once when it clears. */
    protected function alert(BalanceCheck $check): void
    {
        $problems = $check->problems ?? [];
        $last = BalanceCheck::whereNotNull('alerted_at')->where('id', '<', $check->id)->latest('id')->first();
        $lastKeys = collect($last?->problems ?? [])->keys()->sort()->values()->all();
        $keys = collect($problems)->keys()->sort()->values()->all();

        if ($problems) {
            $repeat = $last && $lastKeys === $keys && $last->alerted_at->gt(now()->subHours((int) config('payments.balance_check.realert_hours', 24)));
            if ($repeat) {
                return;
            }
            $mail = new NoticeMail(
                subject: 'Reconciliation: '.(isset($problems['shortfall']) ? 'landlord funds not fully covered' : (isset($problems['difference']) ? 'Yo! balance differs from the books' : 'Yo! balance check failing')),
                heading: 'The money check needs attention',
                lines: array_merge(array_values($problems), ['Checked '.$check->checked_at->format('d M Y, H:i').'. Open Reconciliation in Books to see the details and what may explain it.']),
                actionText: 'Open reconciliation',
                actionUrl: route('staff.books.reconciliation'),
            );
        } elseif ($last && $lastKeys) {
            $mail = new NoticeMail(
                subject: 'Reconciliation: back in step',
                heading: 'The money check is clear again',
                lines: ['Yo! and the books agree, and landlord funds are covered (checked '.$check->checked_at->format('d M Y, H:i').').'],
                actionText: 'Open reconciliation',
                actionUrl: route('staff.books.reconciliation'),
            );
        } else {
            return;
        }

        $emails = StaffUser::where('is_active', true)->get()->filter(fn (StaffUser $s) => $s->hasPermission('books.post'))->pluck('email')->filter()->all();
        try {
            if ($emails) {
                Mail::to($emails)->send($mail);
            }
        } catch (\Throwable $e) {
            report($e);
        }
        $check->update(['alerted_at' => now()]);
    }
}
