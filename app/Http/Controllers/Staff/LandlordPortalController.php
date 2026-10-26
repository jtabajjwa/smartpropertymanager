<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Services\StaffActingSession;

/**
 * Enters / leaves "staff mode": the full landlord portal (leases, invoices,
 * payments, deposits, expenses, …) opened by a staff user on the landlord's
 * behalf. The staff user stays signed in as themselves throughout.
 */
class LandlordPortalController extends Controller
{
    public function enter(Landlord $landlord)
    {
        StaffActingSession::start($landlord);

        return redirect()->route('dashboard')
            ->with('success', "You're now working in {$landlord->name}'s portal.");
    }

    public function exit()
    {
        $landlordId = StaffActingSession::stop();

        return $landlordId && Landlord::whereKey($landlordId)->exists()
            ? redirect()->route('staff.landlords.show', $landlordId)
            : redirect()->route('staff.landlords.index');
    }
}
