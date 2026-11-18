<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Revenue-share terms: what a partner earns on their landlords' subscription payments. */
class CommissionPlan extends Model
{
    use Auditable;

    public const BASES = [
        'paid' => 'The amount the landlord paid',
        'paid_less_charges' => 'The amount paid, less the mobile money charge',
    ];

    protected $fillable = ['name', 'base', 'rate_percent', 'months', 'later_rate_percent', 'signup_bonus', 'is_active'];

    protected function casts(): array
    {
        return ['rate_percent' => 'float', 'later_rate_percent' => 'float', 'signup_bonus' => 'float', 'months' => 'integer', 'is_active' => 'boolean'];
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }

    /** The rate for a payment made $monthsIn whole months after the landlord's first payment. */
    public function rateFor(int $monthsIn): float
    {
        return $this->months === null || $monthsIn < $this->months ? $this->rate_percent : $this->later_rate_percent;
    }

    /** "20% for 12 months, then 10% · UGX 10,000 bonus per landlord". */
    public function summary(): string
    {
        $pct = fn (float $v) => rtrim(rtrim(number_format($v, 2), '0'), '.').'%';
        $text = $pct($this->rate_percent).' of each payment';
        if ($this->months !== null) {
            $text .= " for {$this->months} ".($this->months === 1 ? 'month' : 'months').($this->later_rate_percent > 0 ? ', then '.$pct($this->later_rate_percent) : '');
        }
        if ($this->signup_bonus > 0) {
            $text .= ' · UGX '.number_format($this->signup_bonus).' bonus per landlord';
        }

        return $text;
    }
}
