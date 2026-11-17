<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\BalanceCheck;
use App\Services\Reconciliation;
use Illuminate\Http\Request;

/** Books → Reconciliation: Yo! against the books, landlord funds covered, and items to follow up. */
class ReconciliationController extends Controller
{
    public function index(Reconciliation $reconciliation)
    {
        $checks = BalanceCheck::with('checkedBy')->latest('checked_at')->latest('id')->limit(48)->get();

        return view('staff.books.reconciliation', [
            'latest' => $checks->first(),
            'lastGood' => BalanceCheck::where('status', '!=', BalanceCheck::FAILED)->latest('checked_at')->latest('id')->first(),
            'checks' => $checks,
            'openCollections' => $reconciliation->openCollections(),
            'inFlight' => $reconciliation->payoutsInFlight(),
            'followUps' => $reconciliation->followUps(),
            'gateway' => $reconciliation->gatewayName(),
        ]);
    }

    public function check(Request $request, Reconciliation $reconciliation)
    {
        $check = $reconciliation->check($request->user('staff'));

        return redirect()->route('staff.books.reconciliation')->with(...match ($check->status) {
            BalanceCheck::MATCHED => ['success', 'Checked: Yo! matches the books.'],
            BalanceCheck::DIFFERENCE => ['error', 'Checked: Yo! differs from the books by UGX '.number_format($check->difference).'.'],
            default => ['error', 'The check with Yo! failed: '.$check->message],
        });
    }
}
