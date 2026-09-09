<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'min_units',
        'max_units',
        'monthly_price',
        'quarterly_price',
        'annual_price',
    ];

    protected function casts(): array
    {
        return [
            'min_units' => 'integer',
            'max_units' => 'integer',
            'monthly_price' => 'decimal:2',
            'quarterly_price' => 'decimal:2',
            'annual_price' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function coversUnitCount(int $unitCount): bool
    {
        return $unitCount >= $this->min_units
            && ($this->max_units === null || $unitCount <= $this->max_units);
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
}
