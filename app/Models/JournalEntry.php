<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A balanced set of debits and credits (see App\Accounting\Ledger). */
class JournalEntry extends Model
{
    protected $fillable = ['number', 'entry_date', 'description', 'source_type', 'source_id', 'source_event', 'created_by_staff_user_id', 'reverses_entry_id', 'reversed_at'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'reversed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_user_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function total(): float
    {
        return (float) $this->lines->sum('debit');
    }

    public function isManual(): bool
    {
        return $this->source_type === 'manual';
    }

    /** What produced the entry, in words. */
    public function sourceLabel(): string
    {
        return match ($this->source_type) {
            'payment_transaction' => 'Payment received',
            'sms_purchase' => 'SMS purchase',
            'landlord_settlement' => 'Settlement',
            'liquidation' => 'Landlord payout',
            'manual', null => 'Manual entry',
            default => ucfirst(str_replace('_', ' ', $this->source_type)),
        };
    }
}
