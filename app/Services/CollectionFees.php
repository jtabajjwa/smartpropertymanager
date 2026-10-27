<?php

namespace App\Services;

use App\Models\CollectionFeeTier;
use App\Models\Landlord;

/**
 * The charge for collecting a tenant's mobile money payment, from the
 * staff-maintained bands (mirroring Yo!'s tiered inbound charges).
 *
 * The landlord bears it by default (deducted from what Primora settles to
 * them). A landlord may pass it to tenants instead: the tenant then pays the
 * amount due plus the fee, and the landlord receives the full amount due.
 */
class CollectionFees
{
    /** @var \Illuminate\Support\Collection<int, CollectionFeeTier>|null */
    protected $tiers;

    public function tiers()
    {
        return $this->tiers ??= CollectionFeeTier::where('kind', 'collection')->orderBy('min_amount')->get();
    }

    /** Fee for collecting exactly $amount (0 when no band covers it). */
    public function feeFor(float $amount): float
    {
        $tier = $this->tiers()->first(fn (CollectionFeeTier $t) => $amount >= (float) $t->min_amount
            && ($t->max_amount === null || $amount <= (float) $t->max_amount));

        return $tier ? $tier->feeFor($amount) : 0.0;
    }

    /**
     * What to charge the tenant for paying $principal towards rent/invoices.
     *
     * @return array{principal: float, fee: float, charge: float, landlord_amount: float, bearer: string}
     */
    public function quote(Landlord $landlord, float $principal): array
    {
        if (!$landlord->pass_collection_fee) {
            $fee = $this->feeFor($principal);

            return ['principal' => $principal, 'fee' => $fee, 'charge' => $principal, 'landlord_amount' => max(0, $principal - $fee), 'bearer' => 'landlord'];
        }

        $fee = $this->grossUpFee($principal);

        return ['principal' => $principal, 'fee' => $fee, 'charge' => $principal + $fee, 'landlord_amount' => $principal, 'bearer' => 'tenant'];
    }

    /**
     * The smallest fee F such that the charge on (principal + F) is covered
     * by F. The charge is on the total collected, which includes the fee
     * itself, so adding it can move the total into another band, and Yo!'s
     * bands aren't continuous (a flat 300 up to 10,000, then 2% = ~200
     * just above), so we follow the fee until it settles and keep the
     * smallest value that still covers the charge.
     */
    public function grossUpFee(float $principal): float
    {
        $fee = $this->feeFor($principal);
        $covering = [];

        for ($i = 0; $i < 10; $i++) {
            $next = $this->feeFor($principal + $fee);
            if ($next <= $fee) {
                $covering[] = $fee;
                if ($next == $fee) {
                    break;
                }
            }
            $fee = $next;
        }

        return $covering ? min($covering) : $fee;
    }

    /** Bands in the shape the payment page's calculator uses. */
    public function forScript(): array
    {
        return $this->tiers()->map(fn (CollectionFeeTier $t) => [
            'min' => (float) $t->min_amount,
            'max' => $t->max_amount === null ? null : (float) $t->max_amount,
            'fixed' => (float) $t->fixed_fee,
            'percent' => (float) $t->percent_fee,
        ])->values()->all();
    }
}
