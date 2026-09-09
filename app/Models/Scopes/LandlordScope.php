<?php

namespace App\Models\Scopes;

use App\Exceptions\LandlordContextMissingException;
use App\Services\CurrentLandlord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class LandlordScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $landlordId = CurrentLandlord::id();

        if ($landlordId === null) {
            throw new LandlordContextMissingException(get_class($model));
        }

        $builder->where($model->getTable().'.landlord_id', $landlordId);
    }
}
