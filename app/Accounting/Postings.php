<?php

namespace App\Accounting;

use App\Models\JournalEntry;
use App\Models\LandlordSettlement;
use App\Models\Liquidation;
use App\Models\PaymentTransaction;
use App\Models\SmsPurchase;
use App\Services\CollectionFees;
use App\Services\PayoutFees;

/**
 * The automatic entries: what each event in the app does to Primora's books.
 * Each is keyed by its source, so calling these again (e.g. a backfill) never
 * double-posts.
 *
 * Yo! deducts its own charge from the pool on every collection and withdrawal.
 * We estimate it with the fee bands staff keep in line with Yo!'s tariff;
 * differences can be corrected with a manual journal.
 */
class Postings
{
    public function __construct(protected Ledger $ledger)
    {
    }

    /** Money received for a subscription, SMS bundle or tenant rent. */
    public function paymentSucceeded(PaymentTransaction $t): void
    {
        $amount = (float) $t->amount;
        $date = ($t->completed_at ?? $t->updated_at ?? now());
        $asset = $this->assetFor($t);
        $c = $t->context ?? [];

        $credits = match ($t->purpose) {
            'subscription' => [['account' => 'subscription_income', 'credit' => $amount]],
            'sms' => [['account' => 'sms_income', 'credit' => $amount]],
            'rent' => [
                ['account' => 'landlord_payable', 'credit' => (float) ($t->landlord_amount ?? $amount - (float) $t->fee_amount), 'landlord_id' => $t->landlord_id, 'memo' => ($c['tenant_name'] ?? 'Tenant').(!empty($c['invoice_number']) ? ' · '.$c['invoice_number'] : ' · advance')],
                ['account' => 'collection_fee_income', 'credit' => (float) $t->fee_amount],
            ],
            default => [['account' => 'other_income', 'credit' => $amount]],
        };

        $what = match ($t->purpose) {
            'subscription' => 'Subscription',
            'sms' => 'SMS credit',
            'rent' => 'Rent collected for landlord',
            default => ucfirst($t->purpose),
        };

        $this->ledger->post($date, "{$what} · {$t->reference}".($t->landlord ? " · {$t->landlord->name}" : ''), array_merge(
            [['account' => $asset, 'debit' => $amount]],
            $credits,
        ), 'payment_transaction', $t->id, 'received');

        // Yo! keeps its charge out of every collection that goes through it.
        if ($t->gateway !== 'manual') {
            $charge = app(CollectionFees::class)->feeFor($amount);
            if ($charge > 0) {
                $this->ledger->post($date, "Yo! collection charge · {$t->reference}", [
                    ['account' => 'gateway_charges', 'debit' => $charge],
                    ['account' => 'yo_pool', 'credit' => $charge],
                ], 'payment_transaction', $t->id, 'gateway_charge');
            }
        }
    }

    /** SMS stock bought from a provider (an operating expense). Re-posted when edited. */
    public function smsPurchaseSaved(SmsPurchase $p): void
    {
        $this->replace('sms_purchase', $p->id, $p->purchased_on, "SMS purchase · {$p->provider} · ".number_format($p->quantity).' SMS'.($p->reference ? " · {$p->reference}" : ''), [
            ['account' => 'sms_purchases', 'debit' => (float) $p->total_cost],
            ['account' => $p->paid_from ?: 'bank', 'credit' => (float) $p->total_cost],
        ]);
    }

    public function smsPurchaseDeleted(SmsPurchase $p): void
    {
        $this->replace('sms_purchase', $p->id, now(), null, null, 'SMS purchase deleted');
    }

    /** A payout to a landlord recorded by staff outside the liquidation workflow. */
    public function settlementSaved(LandlordSettlement $s): void
    {
        $from = match ($s->method) {
            'cash' => 'cash',
            'mobile_money' => 'momo_wallet',
            default => 'bank',
        };

        $this->replace('landlord_settlement', $s->id, $s->settled_on, 'Settlement to '.($s->landlord?->name ?? 'landlord').($s->reference ? " · {$s->reference}" : ''), [
            ['account' => 'landlord_payable', 'debit' => (float) $s->amount, 'landlord_id' => $s->landlord_id, 'memo' => 'Settlement'],
            ['account' => $from, 'credit' => (float) $s->amount],
        ]);
    }

    public function settlementDeleted(LandlordSettlement $s): void
    {
        $this->replace('landlord_settlement', $s->id, now(), null, null, 'Settlement deleted');
    }

    /**
     * A landlord paid out. The whole amount leaves what we owe them; they
     * receive the net, and the transfer charge we deducted covers what
     * Yo! / the bank charged us.
     */
    public function liquidationCompleted(Liquidation $l): void
    {
        $from = $l->viaYo() ? 'yo_pool' : 'bank';
        $date = $l->transfer_date ?? $l->completed_at ?? now();

        $this->ledger->post($date, "Payout to {$l->landlord?->name} · {$l->reference}".($l->viaYo() ? ' (via Yo!)' : ' (direct transfer)'), [
            ['account' => 'landlord_payable', 'debit' => (float) $l->amount, 'landlord_id' => $l->landlord_id, 'memo' => 'Payout '.$l->reference],
            ['account' => $from, 'credit' => (float) $l->net_amount],
            ['account' => 'payout_fee_income', 'credit' => (float) $l->fee],
        ], 'liquidation', $l->id, 'paid');

        if ((float) $l->fee > 0) {
            $this->ledger->post($date, ($l->viaYo() ? 'Yo! withdrawal charge' : 'Bank transfer charge')." · {$l->reference}", [
                ['account' => $l->viaYo() ? 'gateway_charges' : 'bank_charges', 'debit' => (float) $l->fee],
                ['account' => $from, 'credit' => (float) $l->fee],
            ], 'liquidation', $l->id, 'transfer_charge');
        }
    }

    /**
     * Keep exactly one live entry for an editable source: reverse whatever is
     * there if it differs, then post the new lines (or just reverse, if null).
     */
    protected function replace(string $type, int $id, $date, ?string $description, ?array $lines, string $reason = 'Updated'): void
    {
        $live = JournalEntry::with('lines')->where('source_type', $type)->where('source_id', $id)
            ->whereNull('reversed_at')->whereNull('reverses_entry_id')->get();

        if ($lines !== null && $live->count() === 1 && $this->same($live->first(), $lines, $date)) {
            return;
        }

        foreach ($live as $entry) {
            $this->ledger->reverse($entry, null, $reason);
        }

        if ($lines !== null) {
            $n = JournalEntry::where('source_type', $type)->where('source_id', $id)->whereNull('reverses_entry_id')->count() + 1;
            $this->ledger->post($date, $description, $lines, $type, $id, 'posted-'.$n);
        }
    }

    protected function same(JournalEntry $entry, array $lines, $date): bool
    {
        if ($entry->entry_date->toDateString() !== \Carbon\Carbon::parse($date)->toDateString()) {
            return false;
        }

        $want = collect($lines)->map(fn ($l) => $this->ledger->account($l['account'])->id.':'.number_format((float) ($l['debit'] ?? 0), 2, '.', '').':'.number_format((float) ($l['credit'] ?? 0), 2, '.', ''))->sort()->values()->all();
        $have = $entry->lines->map(fn ($l) => $l->ledger_account_id.':'.$l->debit.':'.$l->credit)->sort()->values()->all();

        return $want === $have;
    }

    /** Where the money landed. */
    protected function assetFor(PaymentTransaction $t): string
    {
        if ($t->gateway !== 'manual') {
            return 'yo_pool';
        }

        return match ($t->context['method'] ?? null) {
            'cash' => 'cash',
            'mobile_money' => 'momo_wallet',
            default => 'bank',
        };
    }
}
