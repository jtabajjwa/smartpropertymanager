<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A one-time sign-in code sent by SMS to a tenant's phone (stored hashed). */
class TenantLoginCode extends Model
{
    protected $fillable = ['msisdn', 'code_hash', 'attempts', 'expires_at', 'used_at', 'ip'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    }
}
