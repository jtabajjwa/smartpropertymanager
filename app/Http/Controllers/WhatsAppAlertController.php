<?php

namespace App\Http\Controllers;

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\SmsLog;
use App\Models\Tenant;
use App\Models\WhatsAppBundle;
use App\Models\WhatsAppCreditTransaction;
use App\Payments\Msisdn;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;
use App\Services\WhatsAppCredits;
use App\WhatsApp\CloudApi;
use App\WhatsApp\Templates;
use Illuminate\Http\Request;

/**
 * A landlord's WhatsApp alerts: switching them on or off, WhatsApp credit
 * (balance, history, buying bundles by mobile money) and how alerts went.
 */
class WhatsAppAlertController extends Controller
{
    public function show(WhatsAppCredits $credits, PaymentService $payments, CloudApi $wa)
    {
        $landlord = Landlord::findOrFail(CurrentLandlord::id());
        $monthStart = now()->startOfMonth();

        $recentPayments = PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'whatsapp')->latest('id')->limit(10)->get();
        $alerts = SmsLog::where('channel', 'whatsapp')->where('created_at', '>=', $monthStart);

        return view('whatsapp-alerts.show', [
            'landlord' => $landlord,
            'balance' => $landlord->whatsapp_balance,
            'lowThreshold' => $credits->lowBalanceThreshold(),
            'bundles' => WhatsAppBundle::where('is_active', true)->orderBy('sort_order')->orderBy('quantity')->get(),
            'history' => WhatsAppCreditTransaction::where('landlord_id', $landlord->id)->latest('id')->paginate(20),
            'recentPayments' => $recentPayments,
            'awaiting' => $recentPayments->first(fn ($p) => $p->isOpen() && $p->created_at->gt(now()->subMinutes(15))),
            'month' => [
                'sent' => (clone $alerts)->whereIn('status', ['sent', 'delivered', 'read'])->count(),
                'read' => (clone $alerts)->where('status', 'read')->count(),
                'by_sms' => (clone $alerts)->where('status', 'failed')->whereNotNull('resent_log_id')->count(),
            ],
            'tenants' => [
                'total' => Tenant::count(),
                'agreed' => Tenant::whereNotNull('whatsapp_opted_in_at')->whereNull('whatsapp_opted_out_at')->count(),
                'stopped' => Tenant::whereNotNull('whatsapp_opted_out_at')->count(),
            ],
            'ready' => $wa->configured() && collect(array_keys(Templates::all()))->contains(fn ($name) => Templates::approved($name)),
            'available' => $payments->gatewayAvailable(),
            'defaultPhone' => auth()->user()->phone ?? $landlord->contact_phone,
        ]);
    }

    public function toggle(Request $request, WhatsAppCredits $credits)
    {
        $on = $request->boolean('on');
        $landlord = Landlord::findOrFail(CurrentLandlord::id());
        $landlord->forceFill(['whatsapp_alerts' => $on])->save();

        $starter = $on ? $credits->grantStarter($landlord) : null;

        return back()->with('success', $on
            ? 'WhatsApp alerts are on. Tenants who agreed get alerts on WhatsApp; everyone else still gets SMS.'.($starter ? ' '.number_format($starter->quantity).' free WhatsApp alerts were added to get you started.' : '')
            : 'WhatsApp alerts are off. All alerts go by SMS.');
    }

    public function buy(Request $request, PaymentService $payments)
    {
        $data = $request->validate([
            'bundle_id' => ['required', 'exists:whatsapp_bundles,id'],
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $bundle = WhatsAppBundle::where('is_active', true)->find($data['bundle_id']);
        if (!$bundle) {
            return back()->withInput()->with('error', 'That bundle is no longer available. Please choose another.');
        }

        $msisdn = Msisdn::normalize($data['phone']);
        if (!$msisdn) {
            return back()->withInput()->withErrors(['phone' => 'Enter a Ugandan mobile money number, e.g. 0772 123 456.']);
        }

        $landlord = Landlord::findOrFail(CurrentLandlord::id());

        if (PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'whatsapp')->open()->where('created_at', '>', now()->subMinutes(3))->exists()) {
            return back()->with('error', 'A payment request is already waiting for approval on a phone. Approve or decline it first, or try again in a few minutes.');
        }

        try {
            $transaction = $payments->initiate(
                purpose: 'whatsapp',
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

        return redirect()->route('whatsapp-alerts.show')->with('success', $transaction->status === PaymentTransaction::SUCCEEDED
            ? "Payment received: {$bundle->quantity} WhatsApp alerts added."
            : 'Payment request sent to '.$transaction->displayMsisdn().'. Approve it on the phone.');
    }
}
