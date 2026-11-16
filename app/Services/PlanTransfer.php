<?php

namespace App\Services;

use App\Models\Plan;
use App\Support\PlanFeatures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Moving subscription plans between environments (e.g. set up on staging,
 * then copied to production) as a JSON file. Plans are matched by name
 * (ignoring case); an import creates new plans and updates matching ones,
 * and never deletes: landlords' subscriptions point at existing plans.
 */
class PlanTransfer
{
    public const FORMAT = 'primora-plans';
    public const VERSION = 1;

    /** The fields compared and copied (besides tiers). */
    private const FIELDS = ['pricing_model', 'monthly_price', 'quarterly_price', 'annual_price', 'currency', 'is_active', 'show_on_website', 'features'];

    public function export(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'source' => config('app.url'),
            'plans' => Plan::with('tiers')->orderBy('name')->get()->map(fn (Plan $p) => $this->planData($p))->values()->all(),
        ];
    }

    /**
     * Read an export file. Returns the plans it holds (cleaned up), or the
     * reasons it can't be imported.
     *
     * @return array{plans: list<array>, errors: list<string>, warnings: list<string>, source: ?string, exported_at: ?string}
     */
    public function parse(string $json): array
    {
        $out = ['plans' => [], 'errors' => [], 'warnings' => [], 'source' => null, 'exported_at' => null];
        $data = json_decode($json, true);

        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['plans'] ?? null)) {
            $out['errors'][] = 'This is not a Primora plans export file.';

            return $out;
        }
        if ((int) ($data['version'] ?? 0) > self::VERSION) {
            $out['errors'][] = 'This file comes from a newer version of '.config('app.name').'. Deploy the latest version here first.';

            return $out;
        }
        $out['source'] = is_string($data['source'] ?? null) ? $data['source'] : null;
        $out['exported_at'] = is_string($data['exported_at'] ?? null) ? $data['exported_at'] : null;

        $seen = [];
        foreach ($data['plans'] as $i => $plan) {
            $label = is_array($plan) && is_string($plan['name'] ?? null) ? '"'.$plan['name'].'"' : 'Plan '.($i + 1);
            if (!is_array($plan)) {
                $out['errors'][] = "{$label}: not a plan.";
                continue;
            }

            $v = Validator::make($plan, [
                'name' => 'required|string|max:255',
                'pricing_model' => 'required|in:flat,tiered',
                'currency' => 'required|string|size:3',
                'monthly_price' => 'required|numeric|min:0',
                'quarterly_price' => 'required|numeric|min:0',
                'annual_price' => 'required|numeric|min:0',
                'is_active' => 'boolean',
                'show_on_website' => 'boolean',
                'features' => 'nullable|array',
                'features.*' => 'string',
                'tiers' => 'array',
                'tiers.*.min_units' => 'required|integer|min:0',
                'tiers.*.max_units' => 'nullable|integer|min:0',
                'tiers.*.monthly_price' => 'required|numeric|min:0',
                'tiers.*.quarterly_price' => 'required|numeric|min:0',
                'tiers.*.annual_price' => 'required|numeric|min:0',
            ]);
            if ($v->fails()) {
                $out['errors'][] = "{$label}: ".implode(' ', $v->errors()->all());
                continue;
            }

            $key = mb_strtolower(trim($plan['name']));
            if (isset($seen[$key])) {
                $out['errors'][] = "{$label} appears more than once in the file.";
                continue;
            }
            $seen[$key] = true;

            $tiers = collect($plan['tiers'] ?? [])->map(fn ($t) => [
                'min_units' => (int) $t['min_units'],
                'max_units' => isset($t['max_units']) ? (int) $t['max_units'] : null,
                'monthly_price' => round((float) $t['monthly_price'], 2),
                'quarterly_price' => round((float) $t['quarterly_price'], 2),
                'annual_price' => round((float) $t['annual_price'], 2),
            ])->sortBy('min_units')->values()->all();

            if ($plan['pricing_model'] === 'tiered' && ($problem = $this->tierProblem($tiers))) {
                $out['errors'][] = "{$label}: {$problem}";
                continue;
            }

            // Features this installation doesn't know are dropped (and reported).
            $features = $plan['features'] ?? null;
            if (is_array($features)) {
                $unknown = array_diff($features, array_keys(PlanFeatures::FEATURES));
                if ($unknown) {
                    $out['warnings'][] = "{$label}: features not known here are skipped (".implode(', ', $unknown).').';
                }
                $features = PlanFeatures::normalise($features);
            }

            $out['plans'][] = [
                'name' => trim($plan['name']),
                'pricing_model' => $plan['pricing_model'],
                'monthly_price' => $plan['pricing_model'] === 'tiered' ? 0.0 : round((float) $plan['monthly_price'], 2),
                'quarterly_price' => $plan['pricing_model'] === 'tiered' ? 0.0 : round((float) $plan['quarterly_price'], 2),
                'annual_price' => $plan['pricing_model'] === 'tiered' ? 0.0 : round((float) $plan['annual_price'], 2),
                'currency' => strtoupper($plan['currency']),
                'is_active' => (bool) ($plan['is_active'] ?? true),
                'show_on_website' => (bool) ($plan['show_on_website'] ?? false),
                'features' => $features,
                'tiers' => $plan['pricing_model'] === 'tiered' ? $tiers : [],
            ];
        }

        if (!$out['plans'] && !$out['errors']) {
            $out['errors'][] = 'The file has no plans.';
        }

        return $out;
    }

    /**
     * What an import would do, plan by plan.
     *
     * @return list<array{name: string, action: 'create'|'update'|'same', changes: list<string>}>
     */
    public function preview(array $plans): array
    {
        $existing = Plan::with('tiers')->get()->keyBy(fn (Plan $p) => mb_strtolower(trim($p->name)));

        return array_map(function (array $plan) use ($existing) {
            $current = $existing[mb_strtolower($plan['name'])] ?? null;
            if (!$current) {
                return ['name' => $plan['name'], 'action' => 'create', 'changes' => []];
            }
            $changes = $this->differences($this->planData($current), $plan);

            return ['name' => $plan['name'], 'action' => $changes ? 'update' : 'same', 'changes' => $changes];
        }, $plans);
    }

    /** Create and update the plans, all or nothing. @return array{created: int, updated: int, same: int} */
    public function apply(array $plans): array
    {
        return DB::transaction(function () use ($plans) {
            $counts = ['created' => 0, 'updated' => 0, 'same' => 0];
            $existing = Plan::with('tiers')->lockForUpdate()->get()->keyBy(fn (Plan $p) => mb_strtolower(trim($p->name)));

            foreach ($plans as $plan) {
                $current = $existing[mb_strtolower($plan['name'])] ?? null;
                if ($current && !$this->differences($this->planData($current), $plan)) {
                    $counts['same']++;
                    continue;
                }

                $attributes = array_intersect_key($plan, array_flip(self::FIELDS));
                if ($current) {
                    $current->update($attributes);
                    $counts['updated']++;
                } else {
                    $current = Plan::create(['name' => $plan['name']] + $attributes);
                    $counts['created']++;
                }

                $current->tiers()->delete();
                foreach ($plan['tiers'] as $tier) {
                    $current->tiers()->create($tier);
                }
            }

            return $counts;
        });
    }

    private function planData(Plan $p): array
    {
        return [
            'name' => $p->name,
            'pricing_model' => $p->pricing_model ?? 'flat',
            'monthly_price' => round((float) $p->monthly_price, 2),
            'quarterly_price' => round((float) $p->quarterly_price, 2),
            'annual_price' => round((float) $p->annual_price, 2),
            'currency' => $p->currency,
            'is_active' => (bool) $p->is_active,
            'show_on_website' => (bool) $p->show_on_website,
            'features' => $p->features,
            'tiers' => $p->tiers->sortBy('min_units')->values()->map(fn ($t) => [
                'min_units' => (int) $t->min_units,
                'max_units' => $t->max_units === null ? null : (int) $t->max_units,
                'monthly_price' => round((float) $t->monthly_price, 2),
                'quarterly_price' => round((float) $t->quarterly_price, 2),
                'annual_price' => round((float) $t->annual_price, 2),
            ])->all(),
        ];
    }

    /** Human-readable differences between a plan here and in the file. */
    private function differences(array $current, array $incoming): array
    {
        $labels = [
            'pricing_model' => 'Pricing', 'monthly_price' => 'Monthly price', 'quarterly_price' => 'Quarterly price', 'annual_price' => 'Annual price',
            'currency' => 'Currency', 'is_active' => 'Active', 'show_on_website' => 'On website',
        ];
        $show = fn ($v) => match (true) {
            is_bool($v) => $v ? 'yes' : 'no',
            is_float($v) || is_int($v) => number_format($v),
            default => (string) $v,
        };

        $changes = [];
        foreach ($labels as $field => $label) {
            if ($current[$field] !== $incoming[$field]) {
                $changes[] = "{$label}: {$show($current[$field])} → {$show($incoming[$field])}";
            }
        }

        $featureNames = fn (?array $keys) => $keys === null ? 'all' : (count($keys) ? implode(', ', array_map(fn ($k) => PlanFeatures::FEATURES[$k] ?? $k, $keys)) : 'none');
        $norm = fn (?array $keys) => $keys === null ? null : collect($keys)->sort()->values()->all();
        if ($norm($current['features']) !== $norm($incoming['features'])) {
            $changes[] = 'Features: '.$featureNames($current['features']).' → '.$featureNames($incoming['features']);
        }

        if ($current['tiers'] != $incoming['tiers']) {
            $changes[] = 'Tiers: '.count($current['tiers']).' → '.count($incoming['tiers']).' (prices or unit ranges changed)';
        }

        return $changes;
    }

    private function tierProblem(array $tiers): ?string
    {
        if (!$tiers) {
            return 'A tiered plan needs at least one tier.';
        }
        $previousMax = null;
        foreach ($tiers as $i => $t) {
            if ($t['max_units'] !== null && $t['max_units'] < $t['min_units']) {
                return 'A tier\'s max units is below its min units.';
            }
            if ($i > 0 && ($previousMax === null || $t['min_units'] <= $previousMax)) {
                return 'Tier ranges overlap, or an open-ended tier is not the last one.';
            }
            $previousMax = $t['max_units'];
        }

        return null;
    }
}
