<?php

namespace App\Http\Controllers;

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\SmsBundle;
use App\Models\SmsCreditTransaction;
use App\Payments\Msisdn;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;
use App\Services\SmsCredits;
use Illuminate\Http\Request;

/**
 * A landlord's SMS credits: balance, history, and buying bundles by mobile money.
 */
class SmsCreditController extends Controller
{
    public function show(SmsCredits $credits, PaymentService $payments)
    {
        $landlord = Landlord::findOrFail(CurrentLandlord::id());

        $history = SmsCreditTransaction::where('landlord_id', $landlord->id)->latest('id')->paginate(20);

        $recentPayments = PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'sms')->latest('id')->limit(10)->get();
        $awaiting = $recentPayments->first(fn ($p) => $p->isOpen() && $p->created_at->gt(now()->subMinutes(15)));

        $usedThisMonth = (int) -SmsCreditTransaction::where('landlord_id', $landlord->id)
            ->whereIn('type', ['usage', 'refund'])->where('created_at', '>=', now()->startOfMonth())->sum('quantity');

        $notSentThisMonth = \App\Models\SmsLog::where('status', 'no_credit')->where('created_at', '>=', now()->startOfMonth())->count();

        return view('sms-credits.show', [
            'landlord' => $landlord,
            'balance' => $landlord->sms_balance,
            'lowThreshold' => $credits->lowBalanceThreshold(),
            'bundles' => SmsBundle::where('is_active', true)->orderBy('sort_order')->orderBy('quantity')->get(),
            'history' => $history,
            'recentPayments' => $recentPayments,
            'awaiting' => $awaiting,
            'usedThisMonth' => $usedThisMonth,
            'notSentThisMonth' => $notSentThisMonth,
            'available' => $payments->gatewayAvailable(),
            'defaultPhone' => auth()->user()->phone ?? $landlord->contact_phone,
        ]);
    }

    public function buy(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'bundle_id' => ['required', 'exists:sms_bundles,id'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $bundle = SmsBundle::where('is_active', true)->find($data['bundle_id']);
        if (!$bundle) {
            return back()->withInput()->with('error', 'That bundle is no longer available. Please choose another.');
        }

        $msisdn = Msisdn::normalize($data['phone']);
        if (!$msisdn) {
            return back()->withInput()->withErrors(['phone' => 'Enter a Ugandan mobile money number, e.g. 0772 123 456.']);
        }

        $landlord = Landlord::findOrFail(CurrentLandlord::id());

        $recent = PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'sms')
            ->open()->where('created_at', '>', now()->subMinutes(3))->exists();
        if ($recent) {
            return back()->with('error', 'A payment request is already waiting for approval on a phone. Approve or decline it first, or try again in a few minutes.');
        }

        try {
            $transaction = $payments->initiate(
                purpose: 'sms',
                landlord: $landlord,
                amount: (float) $bundle->price,
                msisdn: $msisdn,
                context: ['bundle_id' => $bundle->id, 'bundle_name' => $bundle->name, 'quantity' => $bundle->quantity],
                payable: $landlord,
                initiator: auth()->user(),
            );
        } catch (PaymentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sms-credits.show')->with('success', $transaction->status === PaymentTransaction::SUCCEEDED
            ? "Payment received: {$bundle->quantity} SMS added."
            : 'Payment request sent to '.$transaction->displayMsisdn().'. Approve it on the phone.');
    }
}
