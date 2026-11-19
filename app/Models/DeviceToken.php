<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A phone that can receive push notifications: owned by a landlord's User or
 * a Tenant, tied to the app sign-in (Sanctum token) it was registered with.
 */
class DeviceToken extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'access_token_id', 'token', 'platform', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** Only phones still signed in: the sign-in they registered with hasn't been revoked. */
    public function scopeSignedIn(Builder $query): Builder
    {
        return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('personal_access_tokens')
            ->whereColumn('personal_access_tokens.id', 'device_tokens.access_token_id'));
    }
}
