<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One discounted subscription payment. */
class PromotionRedemption extends Model
{
    protected $fillable = [
        'promotion_id', 'landlord_id', 'partner_id', 'partner_code_id', 'payment_transaction_id', 'period_number',
        'list_amount', 'discount_amount', 'primora_share', 'partner_share',
    ];

    protected function casts(): array
    {
        return ['list_amount' => 'float', 'discount_amount' => 'float', 'primora_share' => 'float', 'partner_share' => 'float'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
