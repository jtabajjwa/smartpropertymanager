<?php

namespace App\Console\Commands;

use App\Models\PaymentTransaction;
use App\Payments\PaymentService;
use Illuminate\Console\Command;

/**
 * Backup to gateway notifications (IPNs): asks the gateway for the status of
 * payments that are still open. Runs every minute from the scheduler.
 *
 *  - Young payments (fast window) are checked every run.
 *  - Older ones are checked every "slow interval".
 *  - After "give up" hours they are left for staff to follow up.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--reference= : Check one payment now, whatever its age}';

    protected $description = 'Check open mobile-money payments with the payment gateway';

    public function handle(PaymentService $payments): int
    {
        $cfg = config('payments.reconcile');

        $query = PaymentTransaction::open()->orderBy('created_at');

        if ($reference = $this->option('reference')) {
            $query->where('reference', $reference);
        } else {
            $now = now();
            $fastSince = $now->copy()->subMinutes($cfg['fast_window_minutes']);

            $query->where('created_at', '>=', $now->copy()->subHours($cfg['give_up_after_hours']))
                ->where(function ($q) use ($now, $fastSince, $cfg) {
                    $q->whereNull('last_checked_at')
                        // young: at most every ~50s
                        ->orWhere(fn ($q) => $q->where('created_at', '>=', $fastSince)->where('last_checked_at', '<=', $now->copy()->subSeconds(50)))
                        // older: every slow interval
                        ->orWhere('last_checked_at', '<=', $now->copy()->subMinutes($cfg['slow_interval_minutes']));
                });
        }

        $checked = 0;
        $changed = 0;

        foreach ($query->limit(100)->get() as $transaction) {
            $before = $transaction->status;

            try {
                $after = $payments->refresh($transaction)->status;
            } catch (\Throwable $e) {
                $this->error("{$transaction->reference}: {$e->getMessage()}");
                report($e);

                continue;
            }

            $checked++;
            if ($after !== $before) {
                $changed++;
                $this->line("{$transaction->reference}: {$before} → {$after}");
            }
        }

        $this->info("Checked {$checked} open payment(s); {$changed} changed.");

        // Landlord payouts sent through the gateway and not yet confirmed.
        if (!$this->option('reference')) {
            $this->reconcilePayouts();
            $this->reconcileUssd();
        }

        return self::SUCCESS;
    }

    /** USSD payment references whose notification may have gone missing (see UssdPayments::reconcile). */
    protected function reconcileUssd(): void
    {
        if (!app(PaymentService::class)->gatewayAvailable('yo')) {
            return;
        }

        try {
            if ($found = app(\App\Ussd\UssdPayments::class)->reconcile()) {
                $this->info("Found {$found} USSD payment(s) without a notification.");
            }
        } catch (\Throwable $e) {
            $this->error("USSD references: {$e->getMessage()}");
            report($e);
        }
    }

    protected function reconcilePayouts(): void
    {
        $payouts = \App\Models\Liquidation::withoutGlobalScopes()->where('status', \App\Models\Liquidation::PROCESSING)
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subSeconds(50)))
            ->orderBy('sent_at')->limit(50)->get();

        foreach ($payouts as $liquidation) {
            try {
                $after = app(\App\Services\Liquidations::class)->refresh($liquidation)->status;
                if ($after !== \App\Models\Liquidation::PROCESSING) {
                    $this->line("{$liquidation->reference}: processing → {$after}");
                }
            } catch (\Throwable $e) {
                $this->error("{$liquidation->reference}: {$e->getMessage()}");
                report($e);
            }
        }

        if ($payouts->isNotEmpty()) {
            $this->info("Checked {$payouts->count()} payout(s) in progress.");
        }
    }
}
