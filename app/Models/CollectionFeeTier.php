<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Charge for collecting one mobile money payment of an amount in [min, max] (mirrors Yo!'s inbound bands). */
class CollectionFeeTier extends Model
{
    use Auditable;

    protected $fillable = ['min_amount', 'max_amount', 'fixed_fee', 'percent_fee'];

    protected function casts(): array
    {
        return ['min_amount' => 'decimal:2', 'max_amount' => 'decimal:2', 'fixed_fee' => 'decimal:2', 'percent_fee' => 'decimal:3'];
    }

    public function feeFor(float $amount): float
    {
        return (float) ceil((float) $this->fixed_fee + $amount * (float) $this->percent_fee / 100);
    }

    public function label(): string
    {
        $min = number_format((float) $this->min_amount);

        return $this->max_amount === null ? "{$min} and above" : $min.' – '.number_format((float) $this->max_amount);
    }
}
