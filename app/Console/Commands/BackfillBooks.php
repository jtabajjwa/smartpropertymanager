<?php

namespace App\Console\Commands;

use App\Accounting\Postings;
use App\Models\JournalEntry;
use App\Models\LandlordSettlement;
use App\Models\Liquidation;
use App\Models\PaymentTransaction;
use App\Models\SmsPurchase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Post everything that happened before the books existed (or any posting
 * that failed at the time). Safe to run repeatedly: each event posts once.
 */
class BackfillBooks extends Command
{
    protected $signature = 'books:backfill';

    protected $description = "Post past payments, SMS purchases, settlements and payouts to Primora's books (idempotent)";

    public function handle(Postings $postings): int
    {
        $before = JournalEntry::count();
        $failed = 0;

        $run = function (string $label, $items, callable $post) use (&$failed) {
            $count = 0;
            foreach ($items as $item) {
                try {
                    DB::transaction(fn () => $post($item));
                    $count++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$label} #{$item->id}: {$e->getMessage()}");
                }
            }
            $this->line("  {$label}: {$count} checked");
        };

        $run('Payments', PaymentTransaction::with('landlord')->where('status', PaymentTransaction::SUCCEEDED)->orderBy('completed_at')->get(),
            fn ($t) => $postings->paymentSucceeded($t));
        $run('SMS purchases', SmsPurchase::orderBy('purchased_on')->get(), fn ($p) => $postings->smsPurchaseSaved($p));
        $run('Settlements', LandlordSettlement::with('landlord')->orderBy('settled_on')->get(), fn ($s) => $postings->settlementSaved($s));
        $run('Payouts', Liquidation::withoutGlobalScopes()->with('landlord')->where('status', Liquidation::COMPLETED)->orderBy('completed_at')->get(),
            fn ($l) => $postings->liquidationCompleted($l));

        $this->info('Journal entries added: '.(JournalEntry::count() - $before).($failed ? ", {$failed} failed" : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
