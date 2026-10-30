<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment reference given to Yo!'s Custom USSD app (see App\Ussd\UssdPayments).
 *
 * Not BelongsToLandlord: created and claimed from Yo!'s call-outs and
 * notifications, outside any landlord's session.
 */
class UssdPaymentIntent extends Model
{
    public const OPEN = 'open';
    public const CLAIMED = 'claimed';
    public const EXPIRED = 'expired';

    protected $fillable = [
        'reference', 'landlord_id', 'tenant_id', 'lease_id', 'msisdn', 'amount', 'status', 'payment_transaction_id', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'last_checked_at' => 'datetime',
        ];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withoutGlobalScopes();
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withoutGlobalScopes();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
