<?php

namespace App\Console\Commands;

use App\Models\Liquidation;
use App\Services\Liquidations;
use Illuminate\Console\Command;

/**
 * Backup to staff pressing "Check status": asks Yo! how payouts still being
 * sent are doing, so they complete (and post to the books) or fail on time.
 * Never resends anything. Every 5 minutes.
 */
class ReconcilePayouts extends Command
{
    protected $signature = 'payouts:reconcile';

    protected $description = 'Check payouts still being sent with the payment gateway';

    public function handle(Liquidations $liquidations): int
    {
        $due = Liquidation::withoutGlobalScopes()->where('status', Liquidation::PROCESSING)->whereNotNull('gateway')
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinutes(4)))
            ->oldest('sent_at')->limit(50)->get();

        $changed = 0;
        foreach ($due as $liquidation) {
            try {
                $after = $liquidations->refresh($liquidation);
            } catch (\Throwable $e) {
                $this->error("{$liquidation->reference}: {$e->getMessage()}");
                report($e);

                continue;
            }
            if ($after->status !== Liquidation::PROCESSING) {
                $changed++;
                $this->line("{$liquidation->reference}: {$after->status}");
            }
        }

        $this->info("Checked {$due->count()} payout(s); {$changed} settled.");

        return self::SUCCESS;
    }
}
