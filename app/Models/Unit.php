<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use App\Traits\HasPhoto;
use App\Support\UnitCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory, Auditable, BelongsToLandlord, HasPhoto;

    protected $fillable = [
        'name',
        'description',
        'status',
        'estate_id',
    ];

    /** Every unit gets a permanent, platform-wide unique ID that tenants sign in with (see UnitCode). */
    protected static function booted(): void
    {
        static::creating(function (Unit $unit) {
            $unit->code ??= UnitCode::nextFor($unit->landlord_id);
        });
    }

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Estate::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function currentLease(): ?Lease
    {
        return $this->leases->where('status', 'active')->sortByDesc('start_date')->first();
    }

    public function currentTenant(): ?Tenant
    {
        return $this->currentLease()?->tenant;
    }
}
