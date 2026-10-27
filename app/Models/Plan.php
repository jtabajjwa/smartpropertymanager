<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'pricing_model',
        'monthly_price',
        'quarterly_price',
        'annual_price',
        'currency',
        'is_active',
        'show_on_website',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'quarterly_price' => 'decimal:2',
            'annual_price' => 'decimal:2',
            'is_active' => 'boolean',
            'show_on_website' => 'boolean',
            'features' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(PlanTier::class)->orderBy('min_units');
    }

    /** No list = every feature (including ones added later). */
    public function includes(string $feature): bool
    {
        return $this->features === null || in_array($feature, $this->features, true);
    }

    /** @return list<string> the feature keys this plan includes */
    public function featureKeys(): array
    {
        return array_values(array_filter(array_keys(\App\Support\PlanFeatures::FEATURES), fn ($k) => $this->includes($k)));
    }

    public function isTiered(): bool
    {
        return $this->pricing_model === 'tiered';
    }

    public function tierForUnitCount(int $unitCount): ?PlanTier
    {
        return $this->tiers->first(fn (PlanTier $tier) => $tier->coversUnitCount($unitCount));
    }

    public function nextTierAfter(int $unitCount): ?PlanTier
    {
        return $this->tiers
            ->filter(fn (PlanTier $tier) => $tier->min_units > $unitCount)
            ->sortBy('min_units')
            ->first();
    }

    public function priceFor(string $billingCycle): float
    {
        return (float) match ($billingCycle) {
            'monthly' => $this->monthly_price,
            'quarterly' => $this->quarterly_price,
            'annual' => $this->annual_price,
            default => $this->monthly_price,
        };
    }

    public function priceForUnits(int $unitCount, string $billingCycle): ?float
    {
        if (!$this->isTiered()) {
            return $this->priceFor($billingCycle);
        }

        return $this->tierForUnitCount($unitCount)?->priceFor($billingCycle);
    }
}
