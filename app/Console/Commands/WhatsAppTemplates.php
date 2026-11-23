<?php

namespace App\Console\Commands;

use App\Models\Scopes\LandlordScope;
use App\Models\SmsLog;
use App\Services\CurrentLandlord;
use App\Services\WhatsAppAlerts;
use App\WhatsApp\Templates;
use Illuminate\Console\Command;

/**
 * The WhatsApp alert templates: shows Meta's status for each (and saves it,
 * so only approved ones are used), --submit sends the ones Meta doesn't have
 * yet for review. Also sends by SMS any WhatsApp alert stuck unsent for over
 * 30 minutes (e.g. the queue worker was down), so no alert is lost.
 */
class WhatsAppTemplates extends Command
{
    protected $signature = 'whatsapp:templates {--submit : Submit templates WhatsApp does not have yet}';

    protected $description = "Check (and with --submit, submit) the WhatsApp alert templates; send stuck WhatsApp alerts by SMS";

    public function handle(WhatsAppAlerts $alerts): int
    {
        $this->sweepStuck($alerts);

        if (!Templates::accountConfigured()) {
            $this->warn('WHATSAPP_TOKEN / WHATSAPP_BUSINESS_ACCOUNT_ID are not set: templates not checked.');

            return self::SUCCESS;
        }

        $statuses = Templates::sync();

        if ($this->option('submit')) {
            foreach ($statuses as $name => $status) {
                if ($status !== 'NOT_SUBMITTED') {
                    continue;
                }
                try {
                    $statuses[$name] = Templates::submit($name);
                    $this->info("Submitted {$name}.");
                } catch (\Throwable $e) {
                    $statuses[$name] = 'NOT_SUBMITTED';
                    $this->error("{$name}: {$e->getMessage()}");
                }
            }
        }

        $this->table(['Template', 'Status'], collect($statuses)->map(fn ($s, $n) => [$n, $s])->values()->all());

        return self::SUCCESS;
    }

    protected function sweepStuck(WhatsAppAlerts $alerts): void
    {
        $stuck = SmsLog::withoutGlobalScope(LandlordScope::class)->where('channel', 'whatsapp')->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(30))->where('created_at', '>', now()->subDays(2))->get();

        foreach ($stuck as $log) {
            CurrentLandlord::runAs($log->landlord_id, fn () => $alerts->failed($log, 'WhatsApp: not sent within 30 minutes'));
        }
        if ($stuck->isNotEmpty()) {
            $this->info("Sent {$stuck->count()} stuck WhatsApp alert(s) by SMS.");
        }
    }
}
