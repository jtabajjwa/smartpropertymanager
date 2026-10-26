<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A package of SMS credits landlords can buy, e.g. 500 SMS for UGX 17,500. */
class SmsBundle extends Model
{
    use Auditable;

    protected $fillable = ['name', 'quantity', 'price', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price' => 'decimal:2', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** Price per SMS, for comparing bundles. */
    public function unitPrice(): float
    {
        return $this->quantity > 0 ? (float) $this->price / $this->quantity : 0.0;
    }
}
