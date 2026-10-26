<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One attempt to collect money through a payment gateway.
 *
 * Deliberately NOT BelongsToLandlord: gateway callbacks and the reconciler
 * run without a landlord context. Landlord-facing queries must filter by
 * landlord_id themselves (see scopeForLandlord).
 */
class PaymentTransaction extends Model
{
    public const PENDING = 'pending';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const INDETERMINATE = 'indeterminate';

    /** Statuses that can still change, so are worth checking again. */
    public const OPEN = [self::PENDING, self::INDETERMINATE];

    protected $fillable = [
        'reference', 'purpose', 'landlord_id', 'payable_type', 'payable_id', 'gateway',
        'amount', 'fee_amount', 'fee_bearer', 'landlord_amount', 'currency', 'msisdn', 'narrative', 'status', 'gateway_reference',
        'network_reference', 'receipt_number', 'status_message', 'context', 'last_response',
        'initiated_by_user_id', 'initiated_by_staff_user_id',
        'last_checked_at', 'notified_at', 'completed_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'landlord_amount' => 'decimal:2',
            'context' => 'array',
            'last_response' => 'array',
            'last_checked_at' => 'datetime',
            'notified_at' => 'datetime',
            'completed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Every exchange with the gateway about this payment, oldest first. */
    public function gatewayLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentGatewayLog::class)->orderBy('id');
    }

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function initiatedByStaffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'initiated_by_staff_user_id');
    }

    public function scopeForLandlord(Builder $query, int $landlordId): Builder
    {
        return $query->where('landlord_id', $landlordId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }

    /** "0772 123 456"-style display of the payer's number. */
    public function displayMsisdn(): string
    {
        return preg_replace('/^256(\d{3})(\d{3})(\d{3})$/', '0$1 $2 $3', $this->msisdn) ?? $this->msisdn;
    }
}
