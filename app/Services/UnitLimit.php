<?php

namespace App\Services;

use App\Models\Landlord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A landlord's paid subscription tier caps how many units they can add
 * (landlords.max_units; null = no limit). Existing units, leases and
 * invoicing are never affected; only adding more is blocked.
 */
class UnitLimit
{
    public function used(Landlord $landlord): int
    {
        return DB::table('units')->where('landlord_id', $landlord->id)->count();
    }

    /** Units that can still be added, or null if there's no limit. */
    public function remaining(Landlord $landlord): ?int
    {
        return $landlord->max_units === null ? null : max(0, $landlord->max_units - $this->used($landlord));
    }

    public function reached(Landlord $landlord): bool
    {
        return $this->remaining($landlord) === 0;
    }

    /**
     * @throws ValidationException when the landlord is at their limit
     */
    public function ensureCanAdd(Landlord $landlord, bool $asStaff = false): void
    {
        if (!$this->reached($landlord)) {
            return;
        }

        $message = "Your subscription covers up to {$landlord->max_units} units, and you already have {$this->used($landlord)}. Upgrade your plan to add more.";

        if ($asStaff) {
            $message = "{$landlord->name}'s subscription covers up to {$landlord->max_units} units and is full. Raise the unit limit under Subscriptions (or record an upgrade payment) to add more.";
        }

        throw ValidationException::withMessages(['units' => $message]);
    }
}
