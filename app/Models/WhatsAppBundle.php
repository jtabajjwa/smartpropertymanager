<?php

namespace App\Models;

/** A package of WhatsApp alert credits landlords can buy (1 credit = 1 alert). */
class WhatsAppBundle extends SmsBundle
{
    protected $table = 'whatsapp_bundles';
}
