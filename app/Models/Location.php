<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'country',
        'district',
        'address',
    ];

    public function estates(): HasMany
    {
        return $this->hasMany(Estate::class);
    }
}
