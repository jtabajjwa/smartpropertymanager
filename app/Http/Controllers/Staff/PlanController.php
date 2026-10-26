<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StorePlanRequest;
use App\Http\Requests\Staff\UpdatePlanRequest;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::with('tiers')->latest()->paginate(15)->withQueryString();

        return view('staff.plans.index', compact('plans'));
    }

    public function store(StorePlanRequest $request)
    {
        $isTiered = $request->pricing_model === 'tiered';

        DB::transaction(function () use ($request, $isTiered) {
            $plan = Plan::create([
                'name' => $request->name,
                'pricing_model' => $request->pricing_model,
                'monthly_price' => $isTiered ? 0 : $request->monthly_price,
                'quarterly_price' => $isTiered ? 0 : $request->quarterly_price,
                'annual_price' => $isTiered ? 0 : $request->annual_price,
                'currency' => strtoupper($request->currency),
                'is_active' => true,
                'show_on_website' => $request->boolean('show_on_website'),
            ]);

            if ($isTiered) {
                $this->syncTiers($plan, $request->input('tiers', []));
            }
        });

        return redirect()->back()->with('success', 'Plan created successfully.');
    }

    public function update(UpdatePlanRequest $request, Plan $plan)
    {
        $isTiered = $request->pricing_model === 'tiered';

        DB::transaction(function () use ($request, $plan, $isTiered) {
            $plan->update([
                'name' => $request->name,
                'pricing_model' => $request->pricing_model,
                'monthly_price' => $isTiered ? 0 : $request->monthly_price,
                'quarterly_price' => $isTiered ? 0 : $request->quarterly_price,
                'annual_price' => $isTiered ? 0 : $request->annual_price,
                'currency' => strtoupper($request->currency),
                'show_on_website' => $request->boolean('show_on_website'),
            ]);

            if ($isTiered) {
                $this->syncTiers($plan, $request->input('tiers', []));
            } else {
                $plan->tiers()->delete();
            }
        });

        return redirect()->back()->with('success', 'Plan updated successfully.');
    }

    public function destroy(Plan $plan)
    {
        $plan->delete();

        return redirect()->back()->with('success', 'Plan deleted successfully.');
    }

    private function syncTiers(Plan $plan, array $tiers): void
    {
        $plan->tiers()->delete();

        foreach ($tiers as $tier) {
            $plan->tiers()->create([
                'min_units' => $tier['min_units'],
                'max_units' => $tier['max_units'] !== '' ? ($tier['max_units'] ?? null) : null,
                'monthly_price' => $tier['monthly_price'],
                'quarterly_price' => $tier['quarterly_price'],
                'annual_price' => $tier['annual_price'],
            ]);
        }
    }
}
