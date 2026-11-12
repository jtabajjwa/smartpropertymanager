<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A one-time code (stored hashed) confirming a sensitive action by a landlord user. See App\Services\ActionCodes. */
class UserActionCode extends Model
{
    protected $fillable = ['user_id', 'purpose', 'code_hash', 'attempts', 'expires_at', 'used_at', 'ip'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    }
}
