<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment a landlord made towards a tax or levy for a fiscal year. */
class TaxPayment extends Model
{
    use Auditable, BelongsToLandlord;

    protected $fillable = ['landlord_id', 'landlord_tax_id', 'fiscal_year', 'amount', 'paid_on', 'reference', 'notes', 'recorded_by_user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'fiscal_year' => 'integer'];
    }

    public function landlordTax(): BelongsTo
    {
        return $this->belongsTo(LandlordTax::class);
    }
}
