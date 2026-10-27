<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-time sign-in code sent to a tenant (stored hashed): by SMS to their
 * phone, or by email (channel "sms" | "email"; destination is the number or address).
 */
class TenantLoginCode extends Model
{
    protected $fillable = ['channel', 'destination', 'code_hash', 'attempts', 'expires_at', 'used_at', 'ip'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    }
}
