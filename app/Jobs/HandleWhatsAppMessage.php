<?php

namespace App\Jobs;

use App\WhatsApp\TenantBot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One incoming WhatsApp message for the tenant bot, handled off the webhook request. */
class HandleWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A reply that fails is better skipped than sent twice. */
    public int $tries = 1;

    public function __construct(public string $waId, public ?string $profileName, public array $message)
    {
    }

    public function handle(TenantBot $bot): void
    {
        $bot->handle($this->waId, $this->profileName, $this->message);
    }
}
