<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerPasswordResetCode extends Model
{
    protected $fillable = ['partner_user_id', 'code', 'used', 'expires_at'];

    protected function casts(): array
    {
        return ['used' => 'boolean', 'expires_at' => 'datetime'];
    }
}
