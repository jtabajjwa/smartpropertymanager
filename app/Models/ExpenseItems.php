<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseItems extends Model
{
    use HasFactory, Auditable, BelongsToLandlord;

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * The starter list every landlord gets: costs landlords record by hand.
     * (Primora's own charges, SMS, fees and bad debts are posted to the books
     * automatically, so they are deliberately not expense items.)
     */
    public const DEFAULTS = [
        'Repairs & maintenance' => 'Plumbing, electrical, painting and other repairs to the property.',
        'Cleaning & garbage' => 'Cleaning of shared areas and garbage collection.',
        'Security' => 'Guards, alarm and CCTV services.',
        'Utilities paid by landlord' => 'Water, electricity and other bills you pay and do not recharge to tenants.',
        'Insurance' => 'Property and liability insurance premiums.',
        'Legal & professional fees' => 'Lawyers, accountants, valuers and surveyors.',
        'Property management fees' => 'Fees paid to agents or managers who run the property for you.',
        'Bank charges' => 'Bank and mobile money charges not already recorded by Primora.',
        'Miscellaneous' => 'Anything that does not fit another expense item.',
    ];

    /** Adds the starter expense items this landlord doesn't already have (matched by name). */
    public static function seedDefaultsFor(int $landlordId): void
    {
        $existing = static::withoutGlobalScopes()->where('landlord_id', $landlordId)->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim($n)))->all();

        foreach (self::DEFAULTS as $name => $description) {
            if (!in_array(mb_strtolower($name), $existing, true)) {
                $item = new static(['name' => $name, 'description' => $description]);
                $item->landlord_id = $landlordId;
                $item->save();
            }
        }
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'expense_item_id');
    }
}
