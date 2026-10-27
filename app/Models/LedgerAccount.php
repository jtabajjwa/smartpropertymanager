<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An account in Primora's chart of accounts. */
class LedgerAccount extends Model
{
    use Auditable;

    /** type => [label, normal balance side, code range hint] */
    public const TYPES = [
        'asset' => ['Assets', 'debit', '1000–1999'],
        'liability' => ['Liabilities', 'credit', '2000–2999'],
        'equity' => ['Equity', 'credit', '3000–3999'],
        'income' => ['Income', 'credit', '4000–4999'],
        'expense' => ['Expenses', 'debit', '5000–5999'],
    ];

    protected $fillable = ['code', 'name', 'type', 'system_key', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public static function key(string $systemKey): self
    {
        return static::where('system_key', $systemKey)->firstOrFail();
    }

    public function isDebitNormal(): bool
    {
        return (self::TYPES[$this->type][1] ?? 'debit') === 'debit';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type][0] ?? ucfirst($this->type);
    }

    /** Accounts that hold Primora's money (can pay for things or receive money). */
    public static function moneyAccounts()
    {
        return static::where('type', 'asset')->where('is_active', true)->orderBy('code')->get();
    }
}
