<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SMS bought by Primora from a provider (stock, and the cost side of SMS sales). */
class SmsPurchase extends Model
{
    use Auditable;

    protected $fillable = ['provider', 'quantity', 'total_cost', 'purchased_on', 'reference', 'notes', 'recorded_by_staff_user_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'total_cost' => 'decimal:2', 'purchased_on' => 'date'];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'recorded_by_staff_user_id');
    }

    public function unitCost(): float
    {
        return $this->quantity > 0 ? (float) $this->total_cost / $this->quantity : 0.0;
    }
}
