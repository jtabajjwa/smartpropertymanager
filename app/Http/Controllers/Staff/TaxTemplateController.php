<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\LandlordTax;
use App\Models\TaxTemplate;
use Illuminate\Http\Request;

/**
 * The taxes and levies landlords can switch on in their Books, with Primora's
 * default rates. Landlords' own figures override these.
 */
class TaxTemplateController extends Controller
{
    public function index()
    {
        return view('staff.books.tax-rates', [
            'templates' => TaxTemplate::orderBy('sort_order')->orderBy('name')->get(),
            'usage' => LandlordTax::withoutGlobalScopes()->where('is_enabled', true)
                ->selectRaw('tax_template_id, COUNT(DISTINCT landlord_id) as landlords')->groupBy('tax_template_id')->pluck('landlords', 'tax_template_id'),
        ]);
    }

    public function update(Request $request, TaxTemplate $taxTemplate)
    {
        $rate = ['required', 'numeric', 'min:0', 'max:100'];
        $money = ['required', 'numeric', 'min:0', 'max:99999999999'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'authority' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'deductible' => ['nullable', 'boolean'],
        ] + match ($taxTemplate->method) {
            'rental_income' => [
                'params.individual.rate' => $rate, 'params.individual.threshold' => $money,
                'params.company.rate' => $rate, 'params.company.expense_cap_percent' => $rate,
            ],
            'bands' => [
                'params.bands' => ['required', 'array', 'min:1', 'max:20'],
                'params.bands.*.min' => $money,
                'params.bands.*.max' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
                'params.bands.*.amount' => $money,
            ],
            'percent_of_value' => ['params.rate' => $rate],
            default => ['params.amount' => $money],
        }, [], ['params.bands.*.min' => 'band from', 'params.bands.*.amount' => 'band amount']);

        $params = $taxTemplate->params ?? [];
        $in = $data['params'] ?? [];
        switch ($taxTemplate->method) {
            case 'rental_income':
                $params['individual'] = ['rate' => (float) $in['individual']['rate'], 'threshold' => (float) $in['individual']['threshold']];
                $params['company'] = ['rate' => (float) $in['company']['rate'], 'expense_cap_percent' => (float) $in['company']['expense_cap_percent']];
                break;
            case 'bands':
                $bands = collect($in['bands'])->map(fn ($b) => [
                    'min' => (float) $b['min'],
                    'max' => ($b['max'] ?? '') === '' || $b['max'] === null ? null : (float) $b['max'],
                    'amount' => (float) $b['amount'],
                ])->sortBy('min')->values();
                foreach ($bands as $i => $band) {
                    if ($band['max'] !== null && $band['max'] < $band['min']) {
                        return back()->withInput()->with('error', 'Each band must end at or above where it starts.');
                    }
                    if ($band['max'] === null && $i < $bands->count() - 1) {
                        return back()->withInput()->with('error', 'Only the last band can be open-ended ("and above").');
                    }
                }
                $params['bands'] = $bands->all();
                break;
            case 'percent_of_value':
                $params['rate'] = (float) $in['rate'];
                break;
            default:
                $params['amount'] = (float) $in['amount'];
        }

        $taxTemplate->update([
            'name' => $data['name'],
            'authority' => $data['authority'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'deductible' => $request->boolean('deductible'),
            'params' => $params,
        ]);

        return redirect()->route('staff.books.tax-rates')->with('success', $taxTemplate->name.' updated.');
    }
}
