<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $flat = $this->input('pricing_model') !== 'tiered';

        return [
            'name' => 'required|string|max:255|unique:plans,name,' . $this->route('plan')->id,
            'pricing_model' => 'required|in:flat,tiered',
            'currency' => 'required|string|size:3',

            'monthly_price' => $flat ? 'required|numeric|min:0' : 'nullable|numeric|min:0',
            'quarterly_price' => $flat ? 'required|numeric|min:0' : 'nullable|numeric|min:0',
            'annual_price' => $flat ? 'required|numeric|min:0' : 'nullable|numeric|min:0',

            'tiers' => $flat ? 'nullable|array' : 'required|array|min:1',
            'tiers.*.min_units' => 'required_with:tiers|integer|min:0',
            'tiers.*.max_units' => 'nullable|integer|gte:tiers.*.min_units',
            'tiers.*.monthly_price' => 'required_with:tiers|numeric|min:0',
            'tiers.*.quarterly_price' => 'required_with:tiers|numeric|min:0',
            'tiers.*.annual_price' => 'required_with:tiers|numeric|min:0',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('pricing_model') !== 'tiered') {
                return;
            }

            $tiers = collect($this->input('tiers', []))
                ->filter(fn ($tier) => isset($tier['min_units']))
                ->sortBy('min_units')
                ->values();

            $previousMax = null;

            foreach ($tiers as $index => $tier) {
                $min = (int) $tier['min_units'];
                $max = isset($tier['max_units']) && $tier['max_units'] !== '' ? (int) $tier['max_units'] : null;

                if ($previousMax !== null && $min <= $previousMax) {
                    $validator->errors()->add("tiers.{$index}.min_units", 'Tier ranges must not overlap with the previous tier.');
                }

                $previousMax = $max;
            }

            if ($tiers->count() > 1 && $tiers->slice(0, -1)->contains(fn ($tier) => !isset($tier['max_units']) || $tier['max_units'] === '')) {
                $validator->errors()->add('tiers', 'Only the last (highest) tier may have an open-ended max units.');
            }
        });
    }
}
