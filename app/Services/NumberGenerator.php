<?php

namespace App\Services;

use App\Models\LandlordNumberSequence;
use Illuminate\Support\Facades\DB;

class NumberGenerator
{
    /**
     * Must be called inside the caller's existing DB::transaction — locks a
     * per-landlord counter row so concurrent creates never collide.
     *
     * With $table given, numbers already used in that table (landlord_id +
     * number) are skipped, e.g. invoices numbered before every invoice used
     * this counter.
     */
    public function next(int $landlordId, string $key, string $prefix, int $pad = 5, ?string $table = null): string
    {
        $row = LandlordNumberSequence::lockForUpdate()->firstOrCreate(
            ['landlord_id' => $landlordId, 'sequence_key' => $key],
            ['next_number' => 1]
        );

        $next = (int) $row->next_number;
        do {
            $number = $prefix.'-'.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
            $next++;
        } while ($table && DB::table($table)->where('landlord_id', $landlordId)->where('number', $number)->exists());

        $row->update(['next_number' => $next]);

        return $number;
    }
}
