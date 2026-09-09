<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'description',
        'status',
        'estate_id',
    ];

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
