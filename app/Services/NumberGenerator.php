<?php

namespace App\Services;

use App\Models\LandlordNumberSequence;

class NumberGenerator
{
    /**
     * Must be called inside the caller's existing DB::transaction — locks a
     * per-landlord counter row so concurrent creates never collide.
     */
    public function next(int $landlordId, string $key, string $prefix, int $pad = 5): string
    {
        $row = LandlordNumberSequence::lockForUpdate()->firstOrCreate(
            ['landlord_id' => $landlordId, 'sequence_key' => $key],
            ['next_number' => 1]
        );

        $number = $row->next_number;
        $row->increment('next_number');

        return $prefix.'-'.str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
    }
}
