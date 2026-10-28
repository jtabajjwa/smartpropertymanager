<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use App\Traits\HasPhoto;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estate extends Model
{
    use HasFactory, Auditable, BelongsToLandlord, HasPhoto;

    protected $fillable = [
        'name',
        'description',
        'address',
        'district',
        'country',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Opens the pin in Google Maps (handy for directions on a phone). */
    public function mapUrl(): ?string
    {
        return $this->hasCoordinates() ? "https://www.google.com/maps?q={$this->latitude},{$this->longitude}" : null;
    }

    /** "Plot 4, Kololo Hill Drive, Kampala" (the country is added outside Uganda). */
    public function fullAddress(): string
    {
        $country = $this->country && $this->country !== \App\Support\Places::DEFAULT_COUNTRY ? $this->country : null;

        return collect([$this->address, $this->district, $country])->map(fn ($p) => trim((string) $p))->filter()->implode(', ');
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
