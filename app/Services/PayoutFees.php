<?php

namespace App\Services;

use App\Models\CollectionFeeTier;

/**
 * The charge deducted from a landlord payout, by method and amount, from the
 * bands staff keep in line with Yo!'s withdrawal tariff (mobile money) and
 * the bank's EFT charges.
 */
class PayoutFees
{
    public const KINDS = [
        'mobile_money' => 'payout_mobile_money',
        'bank' => 'payout_bank',
    ];

    /** @var array<string, \Illuminate\Support\Collection> */
    protected array $tiers = [];

    public function tiers(string $method)
    {
        return $this->tiers[$method] ??= CollectionFeeTier::where('kind', self::KINDS[$method] ?? 'none')->orderBy('min_amount')->get();
    }

    public function feeFor(string $method, float $amount): float
    {
        $tier = $this->tiers($method)->first(fn (CollectionFeeTier $t) => $amount >= (float) $t->min_amount
            && ($t->max_amount === null || $amount <= (float) $t->max_amount));

        return $tier ? $tier->feeFor($amount) : 0.0;
    }

    /** Bands per method, for the request form's live calculator. */
    public function forScript(): array
    {
        return collect(self::KINDS)->map(fn ($kind, $method) => $this->tiers($method)->map(fn (CollectionFeeTier $t) => [
            'min' => (float) $t->min_amount,
            'max' => $t->max_amount === null ? null : (float) $t->max_amount,
            'fixed' => (float) $t->fixed_fee,
            'percent' => (float) $t->percent_fee,
        ])->values())->all();
    }
}
