<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

/** Accepting the current Terms of Service (see EnsureTermsAccepted). */
class TermsController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if (!$user instanceof User || !$user->isLandlordAdmin() || !$user->landlord || $user->landlord->hasAcceptedCurrentTerms()) {
            return redirect()->route('dashboard');
        }

        return view('auth.accept-terms', [
            'landlord' => $user->landlord,
            'firstTime' => !$user->landlord->terms_version,
        ]);
    }

    public function accept(Request $request)
    {
        $request->validate(['terms' => 'accepted'], ['terms.accepted' => 'Please tick the box to agree to the Terms of Service and Privacy Policy.']);

        $user = $request->user();

        if ($user instanceof User && $user->isLandlordAdmin() && $user->landlord) {
            $user->landlord->acceptTerms($user, $request->ip());
        }

        return redirect()->intended(route('dashboard'));
    }
}
