<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\Unit;
use Illuminate\Validation\ValidationException;

class UnitStatusService
{
    /**
     * Mark a unit as occupied. Fails if the unit already has an active lease.
     */
    public function occupy(int $unitId): void
    {
        $unit = Unit::findOrFail($unitId);

        if ($unit->status === 'occupied') {
            $activeLease = Lease::where('unit_id', $unitId)->where('status', 'active')->exists();
            if ($activeLease) {
                throw ValidationException::withMessages([
                    'unit_id' => 'This unit already has an active lease and cannot be leased again.',
                ]);
            }
        }

        $unit->update(['status' => 'occupied']);
    }

    /**
     * Update unit status with validation. Called from UnitController.
     * Cannot change from "occupied" if there's still an active lease.
     */
    public function changeStatus(Unit $unit, string $newStatus): void
    {
        if ($unit->status === $newStatus) {
            return;
        }

        if ($unit->status === 'occupied') {
            $hasActiveLease = Lease::where('unit_id', $unit->id)->where('status', 'active')->exists();
            if ($hasActiveLease) {
                throw ValidationException::withMessages([
                    'status' => 'Cannot change unit status while an active lease exists. Terminate the lease first.',
                ]);
            }
        }

        $unit->update(['status' => $newStatus]);
    }

    /**
     * Handle unit status changes when a lease is created.
     */
    public function onLeaseCreated(Lease $lease): void
    {
        $this->occupy($lease->unit_id);
    }

    /**
     * Handle unit status changes when a lease is updated.
     */
    public function onLeaseUpdated(Lease $lease, ?int $oldUnitId, string $oldStatus): void
    {
        // Unit changed — free old, occupy new
        if ($oldUnitId && $oldUnitId != $lease->unit_id) {
            Unit::where('id', $oldUnitId)->update(['status' => 'vacant']);
            $this->occupy($lease->unit_id);
        }

        // Lease terminated or evicted — free the unit
        if (in_array($lease->status, ['terminated', 'evicted']) && $oldStatus === 'active') {
            Unit::where('id', $lease->unit_id)->update(['status' => 'vacant']);
        }
    }

    /**
     * Handle unit status changes when a lease is deleted.
     */
    public function onLeaseDeleted(Lease $lease): void
    {
        if ($lease->status === 'active') {
            Unit::where('id', $lease->unit_id)->update(['status' => 'vacant']);
        }
    }
}
