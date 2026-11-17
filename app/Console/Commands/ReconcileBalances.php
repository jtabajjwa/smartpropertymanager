<?php

namespace App\Console\Commands;

use App\Models\BalanceCheck;
use App\Services\Reconciliation;
use Illuminate\Console\Command;

/** Compare the Yo! pool balance with the books and check landlord funds are covered. Hourly. */
class ReconcileBalances extends Command
{
    protected $signature = 'reconcile:balances';

    protected $description = 'Check the Yo! balance against the books, and that landlord funds are covered';

    public function handle(Reconciliation $reconciliation): int
    {
        $check = $reconciliation->check();

        $line = match ($check->status) {
            BalanceCheck::MATCHED => 'Yo! balance matches the books: UGX '.number_format($check->partner_balance, 2),
            BalanceCheck::DIFFERENCE => 'Difference with Yo!: '.number_format($check->difference, 2),
            default => 'Check failed: '.$check->message,
        };
        $this->line($line);
        foreach ($check->problems ?? [] as $problem) {
            $this->warn($problem);
        }

        return $check->status === BalanceCheck::FAILED ? self::FAILURE : self::SUCCESS;
    }
}
