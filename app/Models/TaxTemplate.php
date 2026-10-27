<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A tax or levy landlords may owe, with Primora's default rates. Landlords
 * switch on the ones that apply to them (LandlordTax) and can adjust them.
 */
class TaxTemplate extends Model
{
    use Auditable;

    public const METHODS = [
        'rental_income' => 'Rental income tax (individual / company)',
        'bands' => 'Annual amount by income band',
        'percent_of_value' => 'Percentage of rateable value',
        'fixed' => 'Fixed annual amount',
    ];

    protected $fillable = ['code', 'name', 'authority', 'method', 'scope', 'params', 'deductible', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['params' => 'array', 'deductible' => 'boolean', 'is_active' => 'boolean'];
    }

    public function perEstate(): bool
    {
        return $this->scope === 'estate';
    }
}
