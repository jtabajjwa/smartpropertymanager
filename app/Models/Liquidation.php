<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A landlord's request to be paid out the rent Primora collected for them.
 * pending → approved → (processing →) completed, or rejected / cancelled;
 * a mobile money payout that fails can be retried or rejected.
 * See App\Services\Liquidations.
 */
class Liquidation extends Model
{
    use Auditable, BelongsToLandlord;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    /** Still holding the landlord's money (it can't be requested again). */
    public const OPEN = [self::PENDING, self::APPROVED, self::PROCESSING, self::FAILED];

    /** status => [landlord label, staff label, badge colour] */
    public const STATUSES = [
        self::PENDING => ['Awaiting approval', 'Awaiting approval', 'orange'],
        self::APPROVED => ['Approved, being paid', 'Approved, to execute', 'blue'],
        self::PROCESSING => ['Being paid', 'Sent, awaiting confirmation', 'blue'],
        self::COMPLETED => ['Paid', 'Completed', 'green'],
        self::FAILED => ['Being paid', 'Failed, retry or reject', 'red'],
        self::REJECTED => ['Rejected', 'Rejected', 'red'],
        self::CANCELLED => ['Cancelled', 'Cancelled', 'gray'],
    ];

    protected $fillable = [
        'reference', 'landlord_id', 'payout_account_id', 'method', 'route', 'transfer_type', 'destination', 'amount', 'fee', 'net_amount', 'status',
        'request_note', 'requested_by_user_id', 'reviewed_by_staff_user_id', 'reviewed_at', 'review_note',
        'executed_by_staff_user_id', 'executed_at', 'completed_at', 'attempts', 'external_reference', 'gateway',
        'gateway_reference', 'network_reference', 'status_message', 'last_response', 'sent_at', 'last_checked_at',
        'bank_reference', 'transfer_date',
    ];

    protected function casts(): array
    {
        return [
            'destination' => 'array', 'last_response' => 'array',
            'amount' => 'decimal:2', 'fee' => 'decimal:2', 'net_amount' => 'decimal:2',
            'reviewed_at' => 'datetime', 'executed_at' => 'datetime', 'completed_at' => 'datetime',
            'sent_at' => 'datetime', 'last_checked_at' => 'datetime', 'transfer_date' => 'date', 'attempts' => 'integer',
        ];
    }

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(PayoutAccount::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'reviewed_by_staff_user_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'executed_by_staff_user_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PaymentGatewayLog::class)->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isMobileMoney(): bool
    {
        return $this->method === 'mobile_money';
    }

    /** Paid through Yo! Payments (the pool) rather than a direct transfer from Primora's bank. */
    public function viaYo(): bool
    {
        return $this->isMobileMoney() || $this->route === 'yo';
    }

    public function routeLabel(): ?string
    {
        return match (true) {
            $this->isMobileMoney() => 'Yo! Payments (mobile money)',
            $this->route === 'yo' => 'Yo! Payments bank transfer'.($this->transfer_type ? " ({$this->transfer_type})" : ''),
            $this->route === 'direct' => "Direct transfer from Primora's bank",
            default => null,
        };
    }

    public function landlordStatus(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function staffStatus(): string
    {
        return self::STATUSES[$this->status][1] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][2] ?? 'gray';
    }

    public function destinationSummary(): string
    {
        return $this->destination['summary'] ?? '—';
    }
}
