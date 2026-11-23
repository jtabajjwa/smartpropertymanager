<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\SmsLog;
use App\Models\WhatsAppBundle;
use App\Models\WhatsAppCreditTransaction;
use App\Services\WhatsAppCredits;
use App\Support\Settings;
use App\WhatsApp\Templates;
use Illuminate\Http\Request;

/**
 * WhatsApp alerts, staff side: the bundles landlords buy, the free allowance,
 * Meta's approval of each alert template, and landlord balances / corrections.
 * Meta bills Primora for the messages; record that bill as a WhatsApp charges
 * expense in the books.
 */
class WhatsAppController extends Controller
{
    public function index()
    {
        $monthStart = now()->startOfMonth();
        $sentThisMonth = SmsLog::withoutGlobalScopes()->where('channel', 'whatsapp')->where('created_at', '>=', $monthStart);

        return view('staff.sms.whatsapp', [
            'bundles' => WhatsAppBundle::orderBy('sort_order')->orderBy('quantity')->get(),
            'settings' => [
                'starter_credits' => Settings::int('whatsapp.starter_credits', 10),
                'low_balance_threshold' => Settings::int('whatsapp.low_balance_threshold', 10),
            ],
            'templates' => Templates::all(),
            'statuses' => Templates::statuses(),
            'accountReady' => Templates::accountConfigured(),
            'stats' => [
                'landlords_on' => Landlord::where('alert_channels', '!=', 'sms')->count(),
                'sent_month' => (clone $sentThisMonth)->whereIn('status', ['sent', 'delivered', 'read'])->count(),
                'fallback_month' => (clone $sentThisMonth)->where('status', 'failed')->count(),
                'held' => (int) Landlord::sum('whatsapp_balance'),
                'sales_month' => (float) PaymentTransaction::where('purpose', 'whatsapp')->where('status', PaymentTransaction::SUCCEEDED)
                    ->where('completed_at', '>=', $monthStart)->sum('amount'),
            ],
            'landlords' => Landlord::where(fn ($q) => $q->where('alert_channels', '!=', 'sms')->orWhere('whatsapp_balance', '!=', 0))
                ->orderBy('name')->get(['id', 'name', 'trading_name', 'alert_channels', 'whatsapp_balance']),
            'allLandlords' => Landlord::orderBy('name')->get(['id', 'name', 'trading_name']),
            'recent' => WhatsAppCreditTransaction::with(['landlord:id,name', 'staffUser:id,name'])->whereIn('type', ['purchase', 'adjustment', 'starter'])->latest('id')->limit(15)->get(),
        ]);
    }

    // --- Bundles ----------------------------------------------------------

    public function storeBundle(Request $request)
    {
        WhatsAppBundle::create($this->bundleData($request));

        return back()->with('success', 'WhatsApp bundle added.');
    }

    public function updateBundle(Request $request, WhatsAppBundle $bundle)
    {
        $bundle->update($this->bundleData($request));

        return back()->with('success', 'WhatsApp bundle updated.');
    }

    /** Bundles already bought are kept (for history) and only switched off. */
    public function destroyBundle(WhatsAppBundle $bundle)
    {
        if (PaymentTransaction::where('purpose', 'whatsapp')->where('context->bundle_id', $bundle->id)->exists()) {
            $bundle->update(['is_active' => false]);

            return back()->with('success', 'This bundle has been bought before, so it was hidden from landlords instead of deleted.');
        }
        $bundle->delete();

        return back()->with('success', 'WhatsApp bundle deleted.');
    }

    protected function bundleData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'price' => ['required', 'numeric', 'min:500', 'max:999999999'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ], ['price.min' => 'Mobile money payments must be at least UGX 500.']);

        return ['sort_order' => (int) ($data['sort_order'] ?? 0), 'is_active' => $request->boolean('is_active')] + $data;
    }

    // --- Settings & templates -------------------------------------------------

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'starter_credits' => ['required', 'integer', 'min:0', 'max:100000'],
            'low_balance_threshold' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        Settings::set('whatsapp.starter_credits', (string) $data['starter_credits']);
        Settings::set('whatsapp.low_balance_threshold', (string) $data['low_balance_threshold']);

        return back()->with('success', 'WhatsApp settings saved.');
    }

    /** Submits templates Meta doesn't have yet, then reads every template's status. */
    public function syncTemplates(Request $request)
    {
        if (!Templates::accountConfigured()) {
            return back()->with('error', 'Set WHATSAPP_TOKEN and WHATSAPP_BUSINESS_ACCOUNT_ID on the server first.');
        }

        try {
            $statuses = Templates::sync();
            $errors = [];
            if ($request->boolean('submit')) {
                foreach ($statuses as $name => $status) {
                    if ($status === 'NOT_SUBMITTED') {
                        try {
                            Templates::submit($name);
                        } catch (\Throwable $e) {
                            $errors[] = "{$name}: {$e->getMessage()}";
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not reach WhatsApp: '.$e->getMessage());
        }

        return back()->with($errors ? 'error' : 'success', $errors
            ? 'Some templates were not accepted: '.implode(' · ', $errors)
            : ($request->boolean('submit') ? 'Templates submitted. WhatsApp usually reviews them within minutes to a day.' : 'Template statuses updated.'));
    }

    // --- Landlord balances --------------------------------------------------

    public function adjust(Request $request, WhatsAppCredits $credits)
    {
        $data = $request->validate([
            'landlord_id' => ['required', 'exists:landlords,id'],
            'quantity' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'note' => ['required', 'string', 'max:300'],
        ]);

        try {
            $credits->adjust((int) $data['landlord_id'], (int) $data['quantity'], $data['note'], $request->user('staff'));
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'WhatsApp balance adjusted. New balance: '.number_format($credits->balance((int) $data['landlord_id'])).' alerts.');
    }
}
