<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One WhatsApp number talking to the tenant bot: who it is and where the conversation is. */
class WhatsAppSession extends Model
{
    protected $table = 'whatsapp_sessions';

    protected $fillable = ['wa_id', 'profile_name', 'via', 'via_to', 'tenant_id', 'state', 'context', 'last_inbound_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'last_inbound_at' => 'datetime'];
    }

    /** Move the conversation on (null = back to the menu). */
    public function goTo(?string $state, array $context = []): void
    {
        $this->update(['state' => $state, 'context' => $context ?: null]);
    }

    public function ctx(string $key, mixed $default = null): mixed
    {
        return ($this->context ?? [])[$key] ?? $default;
    }
}
