<?php

namespace App\Models;

use App\Models\Scopes\LandlordScope;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum's token, able to load a tenant (the mobile app's tenant side):
 * tenants are scoped to the current landlord, which isn't known until the
 * token's tenant is loaded.
 */
class PersonalAccessToken extends SanctumToken
{
    protected $table = 'personal_access_tokens';

    public function tokenable()
    {
        return $this->morphTo('tokenable')->withoutGlobalScope(LandlordScope::class);
    }
}
