<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StorePlanRequest;
use App\Http\Requests\Staff\UpdatePlanRequest;
use App\Models\Plan;
use App\Services\PlanTransfer;
use Illuminate\Http\Request;
use App\Support\PlanFeatures;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::with('tiers')->latest()->paginate(15)->withQueryString();

        // An uploaded plans file waiting to be applied (see import()).
        $pendingImport = session(self::IMPORT_KEY);
        $importPreview = $pendingImport ? app(PlanTransfer::class)->preview($pendingImport['plans']) : null;

        return view('staff.plans.index', compact('plans', 'pendingImport', 'importPreview'));
    }

    private const IMPORT_KEY = 'staff.plans_import';

    /** All plans as a JSON file, to import into another environment (e.g. staging → production). */
    public function export(PlanTransfer $transfer)
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'primora';
        $name = 'primora-plans-'.\Illuminate\Support\Str::slug($host).'-'.now()->format('Y-m-d-His').'.json';

        return response()->streamDownload(
            fn () => print(json_encode($transfer->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            $name, ['Content-Type' => 'application/json'],
        );
    }

    /** Read an export file and show what it would change; nothing is saved yet. */
    public function import(Request $request, PlanTransfer $transfer)
    {
        $request->validate(['file' => 'required|file|max:1024'], ['file.required' => 'Choose the plans file to import.']);

        $parsed = $transfer->parse((string) file_get_contents($request->file('file')->getRealPath()));
        if ($parsed['errors']) {
            $request->session()->forget(self::IMPORT_KEY);

            return redirect()->route('staff.plans.index')->with('import_errors', $parsed['errors']);
        }

        $request->session()->put(self::IMPORT_KEY, $parsed + ['file' => $request->file('file')->getClientOriginalName()]);

        return redirect()->route('staff.plans.index');
    }

    public function applyImport(Request $request, PlanTransfer $transfer)
    {
        $pending = $request->session()->pull(self::IMPORT_KEY);
        if (!$pending) {
            return redirect()->route('staff.plans.index')->with('error', 'There is no import waiting. Upload the file again.');
        }

        $counts = $transfer->apply($pending['plans']);

        return redirect()->route('staff.plans.index')->with('success', "Plans imported: {$counts['created']} new, {$counts['updated']} updated, {$counts['same']} unchanged.");
    }

    public function cancelImport(Request $request)
    {
        $request->session()->forget(self::IMPORT_KEY);

        return redirect()->route('staff.plans.index');
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
                // The form sends features_sent with its checkboxes; without it, a plan has everything.
                'features' => $request->has('features_sent') ? PlanFeatures::normalise($request->input('features', [])) : null,
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
                'features' => $request->has('features_sent') ? PlanFeatures::normalise($request->input('features', [])) : $plan->features,
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
