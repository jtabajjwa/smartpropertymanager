<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A message to or from the tenant WhatsApp bot. */
class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $fillable = ['wa_id', 'direction', 'wamid', 'type', 'body', 'payload', 'status', 'error', 'tenant_id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
