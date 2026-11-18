<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Someone who signs in to the partner portal for a partner (an agent, or an agency's staff). */
class PartnerUser extends Authenticatable
{
    use Auditable;

    protected $fillable = ['partner_id', 'name', 'email', 'phone', 'password', 'is_active', 'must_change_password', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'must_change_password' => 'boolean', 'last_login_at' => 'datetime'];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
