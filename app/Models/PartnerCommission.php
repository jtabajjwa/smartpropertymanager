<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a partner's earnings: a share of a subscription payment, a
 * sign-up bonus, a discount they bear (negative), a staff adjustment, or a
 * reversal. Earned lines are held until available_at, then can be paid out.
 */
class PartnerCommission extends Model
{
    public const TYPES = [
        'share' => 'Revenue share',
        'bonus' => 'Sign-up bonus',
        'discount' => 'Discount you bear',
        'adjustment' => 'Adjustment',
        'reversal' => 'Reversal',
    ];

    protected $fillable = [
        'partner_id', 'landlord_id', 'payment_transaction_id', 'type', 'description', 'base_amount', 'rate_percent',
        'gross', 'wht_percent', 'wht', 'net', 'earned_at', 'available_at', 'reverses_id', 'reversed_at', 'liquidation_id', 'created_by_staff_user_id',
    ];

    protected function casts(): array
    {
        return [
            'base_amount' => 'float', 'rate_percent' => 'float', 'gross' => 'float', 'wht_percent' => 'float', 'wht' => 'float', 'net' => 'float',
            'earned_at' => 'datetime', 'available_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_user_id');
    }

    /** on_hold | available | paid | reversed */
    public function state(): string
    {
        return match (true) {
            $this->liquidation_id !== null => 'paid',
            $this->reversed_at !== null => 'reversed',
            $this->available_at->isFuture() => 'on_hold',
            default => 'available',
        };
    }

    public function stateLabel(): array
    {
        return match ($this->state()) {
            'paid' => ['Paid out', 'gray'],
            'reversed' => ['Reversed', 'gray'],
            'on_hold' => ['On hold until '.$this->available_at->format('d M'), 'orange'],
            default => ['Available', 'green'],
        };
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
