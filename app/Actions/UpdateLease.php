<?php

namespace App\Actions;

use App\Models\Lease;
use App\Services\UnitStatusService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Changing a lease (e.g. ending it). Once it has invoices, the money terms,
 * tenant, unit and start date are fixed. Ended leases can't be changed.
 * Shared by the web portal and the mobile app's API.
 */
class UpdateLease
{
    public const LOCKED_ONCE_INVOICED = ['rent_amount', 'security_deposit', 'billing_cycle', 'required_upfront_periods', 'tenant_id', 'unit_id', 'start_date'];

    public function __invoke(Lease $lease, array $data, ?UploadedFile $agreement = null): Lease
    {
        if (in_array($lease->status, ['terminated', 'evicted'], true)) {
            throw ValidationException::withMessages(['status' => 'Cannot edit a terminated or evicted lease.']);
        }

        $oldUnitId = $lease->unit_id;
        $oldStatus = $lease->status;

        if ($lease->invoices()->exists()) {
            $data = array_diff_key($data, array_flip(self::LOCKED_ONCE_INVOICED));
        }
        unset($data['agreement_file']);
        if ($agreement) {
            if ($lease->agreement_file) {
                Storage::disk('public')->delete($lease->agreement_file);
            }
            $data['agreement_file'] = $agreement->store('leases/agreements', 'public');
        }

        DB::transaction(function () use ($data, $lease, $oldUnitId, $oldStatus) {
            $lease->update($data);
            app(UnitStatusService::class)->onLeaseUpdated($lease, $oldUnitId, $oldStatus);
        });

        return $lease;
    }
}
