<?php

namespace App\Models;

use App\Payments\Msisdn;
use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mobile money number or bank account a landlord can be paid to (verified by staff before use). */
class PayoutAccount extends Model
{
    use Auditable, BelongsToLandlord;

    public const TYPES = ['mobile_money' => 'Mobile money', 'bank' => 'Bank account'];

    /** status => [label, badge colour] */
    public const STATUSES = [
        'pending' => ['Awaiting verification', 'orange'],
        'verified' => ['Verified', 'green'],
        'rejected' => ['Rejected', 'red'],
        'disabled' => ['Removed', 'gray'],
    ];

    protected $fillable = ['landlord_id', 'type', 'account_name', 'msisdn', 'bank_name', 'bank_branch', 'account_number', 'swift_code', 'yo_bank_identifier', 'yo_bank_info', 'yo_registered_at', 'status', 'review_note', 'created_by_user_id', 'reviewed_by_staff_user_id', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'yo_bank_info' => 'array', 'yo_registered_at' => 'datetime'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'reviewed_by_staff_user_id');
    }

    /** A bank account Yo! Payments can pay (registered on Primora's Yo! account). */
    public function payableViaYo(): bool
    {
        return $this->type === 'mobile_money' || filled($this->yo_bank_identifier);
    }

    public function isUsable(): bool
    {
        return $this->status === 'verified';
    }

    /** "MTN 0772 123 456 · Jane Doe" / "Stanbic · 9030012345678 · Jane Doe" */
    public function summary(): string
    {
        if ($this->type === 'mobile_money') {
            $local = '0'.substr((string) $this->msisdn, 3);
            $pretty = substr($local, 0, 4).' '.substr($local, 4, 3).' '.substr($local, 7);

            return (Msisdn::network((string) $this->msisdn) ?? 'Mobile money').' '.$pretty.' · '.$this->account_name;
        }

        return $this->bank_name.' · '.$this->account_number.' · '.$this->account_name;
    }

    /** What a liquidation keeps, so later edits don't change where past payouts went. */
    public function snapshot(): array
    {
        return array_filter([
            'type' => $this->type,
            'account_name' => $this->account_name,
            'msisdn' => $this->msisdn,
            'bank_name' => $this->bank_name,
            'bank_branch' => $this->bank_branch,
            'account_number' => $this->account_number,
            'swift_code' => $this->swift_code,
            'summary' => $this->summary(),
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'gray';
    }
}
