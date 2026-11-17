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

    /**
     * Categories within each type, in report order: type => [key => [label, hint]].
     * The first is the type's default.
     */
    public const CATEGORIES = [
        'asset' => [
            'current_asset' => ['Current assets', 'Cash, bank, mobile money and anything turned into cash within a year (receivables, prepayments).'],
            'non_current_asset' => ['Non-current assets', 'Kept for more than a year: equipment, vehicles, furniture, software, long-term deposits.'],
        ],
        'liability' => [
            'current_liability' => ['Current liabilities', 'Due within a year: landlord funds, suppliers, taxes, short-term loans.'],
            'non_current_liability' => ['Non-current liabilities', 'Due after more than a year: long-term loans.'],
        ],
        'equity' => [
            'capital' => ["Owner's capital", 'Money put in by the owners, and opening balances.'],
            'reserves' => ['Reserves & retained earnings', 'Profits kept in the business, and reserves.'],
            'drawings' => ['Drawings & dividends', 'Money taken out by the owners.'],
        ],
        'income' => [
            'operating_income' => ['Operating income', 'Income from what Primora sells: subscriptions, SMS, fees.'],
            'other_income' => ['Other income', 'Interest, grants and anything outside normal trading.'],
        ],
        'expense' => [
            'cost_of_sales' => ['Cost of sales', 'Costs that rise with sales: gateway charges, SMS bought for resale.'],
            'operating_expense' => ['Operating expenses', 'Running the business: rent, salaries, internet, marketing.'],
            'finance_cost' => ['Finance costs', 'Bank charges and interest paid.'],
            'tax_expense' => ['Tax', 'Income tax and similar.'],
        ],
    ];

    protected $fillable = ['code', 'name', 'type', 'category', 'system_key', 'description', 'is_active'];

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

    /** The category, or the type's default when none is set. */
    public function categoryKey(): string
    {
        $categories = self::CATEGORIES[$this->type] ?? [];

        return isset($categories[$this->category]) ? $this->category : (string) array_key_first($categories);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->type][$this->categoryKey()][0] ?? $this->typeLabel();
    }

    /** @return list<string> category keys allowed for a type */
    public static function categoriesFor(string $type): array
    {
        return array_keys(self::CATEGORIES[$type] ?? []);
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
