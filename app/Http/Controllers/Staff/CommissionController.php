<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\CommissionPlan;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Services\PartnerCommissions;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff: commission plans and partner-programme settings; adjusting and reversing partner earnings. */
class CommissionController extends Controller
{
    public function index()
    {
        $owed = PartnerCommission::whereNull('liquidation_id')->selectRaw('partner_id, sum(net) as owed')->groupBy('partner_id')->pluck('owed', 'partner_id');

        return view('staff.partners.commissions', [
            'plans' => CommissionPlan::withCount('partners')->orderBy('name')->get(),
            'holdingDays' => PartnerCommissions::holdingDays(),
            'minPayout' => PartnerCommissions::minPayout(),
            'whtPercent' => PartnerCommissions::whtPercent(),
            'defaultPlan' => PartnerCommissions::defaultPlan(),
            'totals' => [
                'owed' => (float) $owed->sum(),
                'available' => (float) PartnerCommission::whereNull('liquidation_id')->where('available_at', '<=', now())->sum('net'),
                'wht' => (float) PartnerCommission::sum('wht'),
                'month' => (float) PartnerCommission::where('earned_at', '>=', now()->startOfMonth())->sum('net'),
            ],
            'top' => Partner::whereIn('id', $owed->keys())->get()->map(fn ($p) => ['partner' => $p, 'owed' => (float) $owed[$p->id]])->sortByDesc('owed')->take(10),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'holding_days' => ['required', 'integer', 'min:0', 'max:180'],
            'min_payout' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'wht_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'default_plan_id' => ['nullable', Rule::exists('commission_plans', 'id')->where('is_active', true)],
        ]);

        Settings::set(PartnerCommissions::HOLDING_DAYS, (int) $data['holding_days']);
        Settings::set(PartnerCommissions::MIN_PAYOUT, (float) $data['min_payout']);
        Settings::set(PartnerCommissions::WHT_PERCENT, (float) $data['wht_percent']);
        Settings::set(PartnerCommissions::DEFAULT_PLAN, $data['default_plan_id'] ?? null);

        return back()->with('success', 'Partner settings saved. They apply to earnings from now on.');
    }

    public function storePlan(Request $request)
    {
        CommissionPlan::create($this->validatedPlan($request));

        return back()->with('success', 'Commission plan added.');
    }

    public function updatePlan(Request $request, CommissionPlan $plan)
    {
        $plan->update($this->validatedPlan($request));

        return back()->with('success', "{$plan->name} saved. It applies to payments from now on; earnings already recorded don't change.");
    }

    public function adjust(Request $request, Partner $partner, PartnerCommissions $commissions)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-100000000', 'max:100000000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ], ['amount.not_in' => 'Enter an amount to add (e.g. 5000) or take off (e.g. -5000).']);

        $commissions->adjust($partner, (float) $data['amount'], $data['reason'], $request->user('staff'));

        return back()->with('success', 'Adjustment of UGX '.number_format((float) $data['amount']).' recorded for '.$partner->name.'.');
    }

    public function reverse(Request $request, PartnerCommission $commission, PartnerCommissions $commissions)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        try {
            $commissions->reverse($commission, $data['reason'], $request->user('staff'));
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reversed: '.$commission->description.'.');
    }

    protected function validatedPlan(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'base' => ['required', Rule::in(array_keys(CommissionPlan::BASES))],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'later_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'signup_bonus' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'months' => $data['months'] ?? null,
            'later_rate_percent' => isset($data['months']) ? (float) ($data['later_rate_percent'] ?? 0) : 0,
            'signup_bonus' => (float) ($data['signup_bonus'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ] + $data;
    }
}
