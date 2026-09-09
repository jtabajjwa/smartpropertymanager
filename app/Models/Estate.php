<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estate extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'description',
        'location_id',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function occupancyRate(): float
    {
        $total = $this->units->count();

        if ($total === 0) {
            return 0;
        }

        return round($this->units->where('status', 'occupied')->count() / $total * 100, 1);
    }

    public function vacantUnitsCount(): int
    {
        return $this->units->where('status', 'vacant')->count();
    }

    public function occupiedUnitsCount(): int
    {
        return $this->units->where('status', 'occupied')->count();
    }
}
