<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Services\SubscriptionBilling;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Staff: subscription discounts given through partner and campaign codes. */
class PromotionController extends Controller
{
    public function index()
    {
        $promotions = Promotion::withCount('codes')->latest()->get();
        $usage = PromotionRedemption::selectRaw('promotion_id, count(distinct landlord_id) as landlords, count(*) as payments, sum(discount_amount) as discount, sum(partner_share) as partner_share')
            ->groupBy('promotion_id')->get()->keyBy('promotion_id');

        return view('staff.partners.promotions', [
            'promotions' => $promotions,
            'usage' => $usage,
            'plans' => Plan::orderBy('name')->get(['id', 'name']),
            'cycles' => SubscriptionBilling::CYCLES,
            'campaignCodes' => \App\Models\PartnerCode::whereNull('partner_id')->with('promotion')->withCount('landlords')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        Promotion::create($this->validated($request));

        return back()->with('success', 'Promotion created. Link it to codes, or make it a partner\'s default.');
    }

    public function update(Request $request, Promotion $promotion)
    {
        $promotion->update($this->validated($request));

        return back()->with('success', "{$promotion->name} saved. Payments already made keep the discount they had.");
    }

    /** A Primora campaign code: a discount with no partner (e.g. LAUNCH20). */
    public function storeCampaignCode(Request $request)
    {
        $request->merge(['code' => \App\Models\PartnerCode::normalize((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:30', 'regex:/^[A-Z0-9-]+$/', 'not_regex:/^\d+$/', 'unique:partner_codes,code'],
            'label' => ['nullable', 'string', 'max:120'],
            'promotion_id' => ['required', 'exists:promotions,id'],
        ], ['code.regex' => 'Use letters, numbers and dashes.', 'code.not_regex' => 'Numbers only are agent numbers; add a letter.', 'code.unique' => 'That code is taken.']);

        \App\Models\PartnerCode::create($data + ['partner_id' => null, 'is_active' => true]);

        return back()->with('success', "Campaign code {$data['code']} added.");
    }

    public function toggleCampaignCode(\App\Models\PartnerCode $code)
    {
        abort_unless($code->partner_id === null, 404);
        $code->update(['is_active' => !$code->is_active]);

        return back()->with('success', "Code {$code->code} ".($code->is_active ? 'switched on.' : 'switched off.'));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:300'],
            'discount_type' => ['required', Rule::in(array_keys(Promotion::TYPES))],
            'discount_value' => ['required', 'numeric', 'min:1', $request->input('discount_type') === 'percent' ? 'max:99' : 'max:100000000'],
            'periods' => ['required', 'integer', 'min:1', 'max:36'],
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => ['integer', 'exists:plans,id'],
            'cycles' => ['nullable', 'array'],
            'cycles.*' => [Rule::in(array_keys(SubscriptionBilling::CYCLES))],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'max_landlords' => ['nullable', 'integer', 'min:1'],
            'funded_by' => ['required', Rule::in(array_keys(Promotion::FUNDERS))],
            'partner_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ], ['discount_value.max' => 'A percentage discount can be at most 99%.']);

        return [
            'plan_ids' => !empty($data['plan_ids']) ? array_map('intval', $data['plan_ids']) : null,
            'cycles' => !empty($data['cycles']) ? array_values($data['cycles']) : null,
            'partner_share_percent' => $data['funded_by'] === 'split' ? (float) ($data['partner_share_percent'] ?? 50) : 0,
            'is_active' => $request->boolean('is_active'),
        ] + $data;
    }
}
