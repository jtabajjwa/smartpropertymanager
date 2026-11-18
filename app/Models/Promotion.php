<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription discount, given through a partner's code (or a Primora
 * campaign code): e.g. 20% off the first 3 paid months. Who bears it is
 * set per promotion; usually Primora (an expense in the books).
 */
class Promotion extends Model
{
    use Auditable;

    public const TYPES = ['percent' => 'Percentage off', 'fixed' => 'Amount off (UGX)'];

    public const FUNDERS = [
        'primora' => 'Primora (recorded as an expense)',
        'partner' => 'The partner (taken from their commission)',
        'split' => 'Split between Primora and the partner',
    ];

    protected $fillable = [
        'name', 'description', 'discount_type', 'discount_value', 'periods', 'plan_ids', 'cycles',
        'starts_on', 'ends_on', 'max_landlords', 'funded_by', 'partner_share_percent', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'float', 'partner_share_percent' => 'float', 'periods' => 'integer', 'max_landlords' => 'integer',
            'plan_ids' => 'array', 'cycles' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(PartnerCode::class);
    }

    /** "20% off your first 3 paid periods". */
    public function summary(): string
    {
        $off = $this->discount_type === 'percent'
            ? rtrim(rtrim(number_format($this->discount_value, 2), '0'), '.').'% off'
            : 'UGX '.number_format($this->discount_value).' off';

        return $off.($this->periods > 1 ? " your first {$this->periods} paid periods" : ' your first paid period');
    }

    /** Running now: switched on, within its dates, and not used by too many landlords yet. */
    public function isRunning(?Landlord $for = null): bool
    {
        if (!$this->is_active || ($this->starts_on && $this->starts_on->isFuture()) || ($this->ends_on && $this->ends_on->lt(today()))) {
            return false;
        }
        if ($this->max_landlords !== null) {
            $users = $this->redemptions()->distinct()->count('landlord_id');
            $alreadyIn = $for && $this->redemptions()->where('landlord_id', $for->id)->exists();
            if ($users >= $this->max_landlords && !$alreadyIn) {
                return false;
            }
        }

        return true;
    }

    public function appliesTo(int $planId, string $cycle): bool
    {
        return (!$this->plan_ids || in_array($planId, array_map('intval', $this->plan_ids), true))
            && (!$this->cycles || in_array($cycle, $this->cycles, true));
    }

    /** The discount on an amount (never the whole amount). */
    public function discountOn(float $amount): float
    {
        $discount = $this->discount_type === 'percent'
            ? round($amount * min($this->discount_value, 99) / 100)
            : min($this->discount_value, $amount);

        return max(0.0, min(round($discount, 2), $amount - 1));
    }

    /** How much of a discount the partner bears (none without a partner). */
    public function partnerShareOf(float $discount, bool $hasPartner): float
    {
        if (!$hasPartner) {
            return 0.0;
        }

        return round(match ($this->funded_by) {
            'partner' => $discount,
            'split' => $discount * $this->partner_share_percent / 100,
            default => 0.0,
        }, 2);
    }
}
