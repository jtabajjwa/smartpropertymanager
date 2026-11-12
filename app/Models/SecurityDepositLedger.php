<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityDepositLedger extends Model
{
    use HasFactory, Auditable, BelongsToLandlord;

    /** Why money was taken from a deposit (deductions). */
    public const CATEGORIES = [
        'damage_repair' => 'Damage repair',
        'cleaning' => 'Cleaning',
        'unpaid_rent' => 'Unpaid rent',
        'unpaid_utilities' => 'Unpaid utilities / bills',
        'other' => 'Other',
    ];

    /** Reasons that are a cost the landlord pays out (so usually also an expense). */
    public const EXPENSE_CATEGORIES = ['damage_repair', 'cleaning', 'other'];

    /** type => [label for tenants, badge colour] */
    public const TYPES = [
        'deposit' => ['Deposit paid', 'green'],
        'deduction' => ['Used', 'red'],
        'refund' => ['Refunded to you', 'orange'],
        'forfeit' => ['Kept by landlord', 'purple'],
    ];

    protected $fillable = [
        'date',
        'lease_id',
        'type',
        'category',
        'amount',
        'notes',
        'attachment',
        'expense_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** "Damage repair: Broken window in the sitting room" / "Refunded to you" */
    public function description(): string
    {
        if ($this->type === 'deduction') {
            $reason = self::CATEGORIES[$this->category] ?? 'Deduction';

            return $this->notes ? "{$reason}: {$this->notes}" : $reason;
        }

        return $this->notes ?: (self::TYPES[$this->type][0] ?? ucfirst($this->type));
    }

    public function categoryLabel(): ?string
    {
        return $this->category ? (self::CATEGORIES[$this->category] ?? ucfirst($this->category)) : null;
    }
}
