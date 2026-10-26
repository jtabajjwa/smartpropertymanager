<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a landlord's SMS credit balance. Written only by
 * App\Services\SmsCredits. Not landlord-scoped by a global scope (staff
 * screens read across landlords); landlord-facing reads filter explicitly.
 */
class SmsCreditTransaction extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'purchase' => 'Bought',
        'starter' => 'Free starter SMS',
        'usage' => 'Used',
        'refund' => 'Returned (not sent)',
        'adjustment' => 'Adjustment by Primora',
    ];

    protected $fillable = ['landlord_id', 'type', 'quantity', 'balance_after', 'payment_transaction_id', 'sms_log_id', 'staff_user_id', 'note'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
