<?php

namespace App\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Runs an automatic posting without letting a bookkeeping problem undo the
 * business action that triggered it (a payment being credited, a purchase
 * being saved). The posting runs in its own savepoint; if it fails, it is
 * logged, and `php artisan books:backfill` will post it later.
 */
final class BookKeeper
{
    public static function attempt(callable $posting): void
    {
        try {
            DB::transaction(fn () => $posting());
        } catch (\Throwable $e) {
            Log::error('Bookkeeping: automatic posting failed; run books:backfill once fixed.', ['error' => $e->getMessage()]);
            report($e);
        }
    }
}
