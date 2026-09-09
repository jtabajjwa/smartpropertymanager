<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandlordNumberSequence extends Model
{
    protected $fillable = [
        'landlord_id',
        'sequence_key',
        'next_number',
    ];

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }
}
