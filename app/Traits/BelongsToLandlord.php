<?php

namespace App\Traits;

use App\Exceptions\LandlordContextMissingException;
use App\Models\Landlord;
use App\Models\Scopes\LandlordScope;
use App\Services\CurrentLandlord;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToLandlord
{
    public static function bootBelongsToLandlord(): void
    {
        static::addGlobalScope(new LandlordScope());

        static::creating(function ($model) {
            if (empty($model->landlord_id)) {
                $landlordId = CurrentLandlord::id();

                if ($landlordId === null) {
                    throw new LandlordContextMissingException(get_class($model));
                }

                $model->landlord_id = $landlordId;
            }
        });
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }
}
