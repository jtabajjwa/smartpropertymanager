<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Landlord extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'trading_name',
        'contact_email',
        'contact_phone',
        'address',
        'status',
        'subscription_status',
        'current_period_end',
        'grace_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'current_period_end' => 'date',
            'grace_ends_at' => 'date',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
