<?php

namespace App\Models;

/** One change to a landlord's WhatsApp alert credit. Written only by App\Services\WhatsAppCredits. */
class WhatsAppCreditTransaction extends SmsCreditTransaction
{
    protected $table = 'whatsapp_credit_transactions';

    public const TYPES = [
        'purchase' => 'Bought',
        'starter' => 'Free starter alerts',
        'usage' => 'Used',
        'refund' => 'Returned (not delivered)',
        'adjustment' => 'Adjustment by Primora',
    ];

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
