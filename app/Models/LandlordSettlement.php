<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payout from Primora to a landlord of rent collected on their behalf. */
class LandlordSettlement extends Model
{
    use Auditable;

    public const METHODS = [
        'bank_transfer' => 'Bank transfer',
        'mobile_money' => 'Mobile money',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'other' => 'Other',
    ];

    protected $fillable = ['landlord_id', 'amount', 'method', 'reference', 'settled_on', 'notes', 'recorded_by_staff_user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'settled_on' => 'date'];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'recorded_by_staff_user_id');
    }
}
