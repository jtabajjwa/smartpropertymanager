<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One comparison of a partner's balance with the books (see App\Services\Reconciliation). */
class BalanceCheck extends Model
{
    public const MATCHED = 'matched';
    public const DIFFERENCE = 'difference';
    public const FAILED = 'failed';

    protected $fillable = [
        'ledger_account_id', 'gateway', 'checked_at', 'status', 'partner_balance', 'book_balance', 'in_flight', 'difference',
        'wallets', 'money_held', 'landlord_funds', 'message', 'problems', 'alerted_at', 'checked_by_staff_user_id',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime', 'alerted_at' => 'datetime', 'wallets' => 'array', 'problems' => 'array',
            'partner_balance' => 'float', 'book_balance' => 'float', 'in_flight' => 'float', 'difference' => 'float',
            'money_held' => 'float', 'landlord_funds' => 'float',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'checked_by_staff_user_id');
    }

    /** Money held minus what landlords are owed; negative is a shortfall. */
    public function cover(): ?float
    {
        return $this->money_held === null ? null : round($this->money_held - $this->landlord_funds, 2);
    }

    public function statusLabel(): array
    {
        return match ($this->status) {
            self::MATCHED => ['Matches the books', 'green'],
            self::DIFFERENCE => ['Difference', 'red'],
            default => ['Check failed', 'gray'],
        };
    }
}
