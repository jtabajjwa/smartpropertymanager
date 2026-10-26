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

        return self::SUCCESS;
    }
}
