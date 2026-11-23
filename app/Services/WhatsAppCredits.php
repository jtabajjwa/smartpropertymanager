<?php

namespace App\Services;

use App\Models\WhatsAppCreditTransaction;

/**
 * A landlord's WhatsApp alert credit: kept exactly like SMS credit (see
 * SmsCredits), in its own balance and ledger. One credit sends one alert,
 * whatever its length.
 */
class WhatsAppCredits extends SmsCredits
{
    protected const COLUMN = 'whatsapp_balance';
    protected const LEDGER = WhatsAppCreditTransaction::class;
    protected const SETTINGS = 'whatsapp';
    public const UNIT = 'WhatsApp alerts';

    public function lowBalanceThreshold(): int
    {
        return \App\Support\Settings::int('whatsapp.low_balance_threshold', 10);
    }

    /** The free allowance, given once: the first time the landlord switches WhatsApp alerts on. */
    public function grantStarter(\App\Models\Landlord $landlord): ?\App\Models\SmsCreditTransaction
    {
        if (WhatsAppCreditTransaction::where('landlord_id', $landlord->id)->where('type', 'starter')->exists()) {
            return null;
        }
        $credits = \App\Support\Settings::int('whatsapp.starter_credits', 10);

        return $credits > 0 ? $this->add($landlord->id, $credits, 'starter', 'Free starter WhatsApp alerts') : null;
    }
}
