<x-app-layout>
    <x-slot name="header">Books settings</x-slot>

    @php
        $num = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $fmt = fn ($v) => number_format((float) $v);
        $months = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Carbon\Carbon::create(2000, $m, 1)->format('F')]);
        // [key, label, kind (percent|money), default] per method; shown for each tax switched on.
        $fields = fn (\App\Models\TaxTemplate $t) => match ($t->method) {
            'rental_income' => $landlord->taxpayer_type === 'company'
                ? [['company_rate', 'Company rate', 'percent', $t->params['company']['rate'] ?? 30], ['expense_cap_percent', 'Expenses capped at (% of income)', 'percent', $t->params['company']['expense_cap_percent'] ?? 50]]
                : [['rate', 'Rate', 'percent', $t->params['individual']['rate'] ?? 12], ['threshold', 'Annual threshold (UGX)', 'money', $t->params['individual']['threshold'] ?? 0]],
            'bands' => [['amount', 'Annual amount (UGX), if your council set it', 'money', null]],
            'percent_of_value' => [['rateable_value', 'Rateable value (UGX)', 'money', null], ['rate', 'Rate', 'percent', $t->params['rate'] ?? 0]],
            default => [['amount', 'Annual amount (UGX)', 'money', $t->params['amount'] ?? 0]],
        };
    @endphp

    <div class="max-w-6xl mx-auto space-y-6">
        @include('books._nav')

        {{-- How the books are kept --}}
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">How your books are kept</h2>
            </div>
            <form method="POST" action="{{ route('books.settings.update') }}" class="p-6 space-y-5">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <x-label>Basis</x-label>
                        <div class="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach(['cash' => ['Cash', 'Income when your tenants pay; expenses when you pay. Simplest, and what most individual landlords use.'], 'accrual' => ['Accrual', 'Income when you invoice; unpaid invoices you write off become bad debts.']] as $value => [$title, $hint])
                                @php $locked = $value === 'accrual' && !$landlord->hasFeature('accrual_basis'); @endphp
                                <label class="flex gap-3 rounded-xl border p-3 transition has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/60 border-gray-200 {{ $locked ? 'cursor-not-allowed bg-gray-50/80' : 'cursor-pointer hover:border-gray-300' }}">
                                    <input type="radio" name="books_basis" value="{{ $value }}" class="mt-0.5 text-orange-500 focus:ring-orange-500" @checked(old('books_basis', $landlord->booksBasis()) === $value) @disabled($locked)>
                                    <span>
                                        <span class="flex items-center gap-1.5 text-sm font-medium {{ $locked ? 'text-gray-500' : 'text-gray-900' }}">{{ $title }}@if($locked) <x-plan-lock /> @endif</span>
                                        <span class="block text-xs text-gray-500">{{ $hint }}</span>
                                        @if($locked)<a href="{{ route('subscription.show') }}" class="mt-1 inline-block text-xs font-medium text-orange-600 hover:text-orange-700">Not in your plan · see plans</a>@endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('books_basis')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <x-label>You file as</x-label>
                        <div class="mt-1 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach(['individual' => ['An individual', 'Rental income tax on gross rent above the threshold; no deductions.'], 'company' => ['A company or partnership', 'Corporate rate on rent less allowable expenses.']] as $value => [$title, $hint])
                                <label class="flex cursor-pointer gap-3 rounded-xl border p-3 transition has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/60 border-gray-200 hover:border-gray-300">
                                    <input type="radio" name="taxpayer_type" value="{{ $value }}" class="mt-0.5 text-orange-500 focus:ring-orange-500" @checked(old('taxpayer_type', $landlord->taxpayer_type) === $value)>
                                    <span><span class="block text-sm font-medium text-gray-900">{{ $title }}</span><span class="block text-xs text-gray-500">{{ $hint }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        @error('taxpayer_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <x-label>Fiscal year starts in</x-label>
                        <x-select name="fiscal_year_start_month">
                            @foreach($months as $m => $name)
                                <option value="{{ $m }}" @selected((int) old('fiscal_year_start_month', $landlord->fiscal_year_start_month ?: 7) === $m)>{{ $name }}{{ $m === 7 ? ' (URA year, July–June)' : ($m === 1 ? ' (calendar year)' : '') }}</option>
                            @endforeach
                        </x-select>
                        @error('fiscal_year_start_month')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <x-label>TIN <span class="font-normal text-gray-400">(shown on your reports)</span></x-label>
                        <x-input name="tin" value="{{ old('tin', $landlord->tin) }}" maxlength="30" placeholder="e.g. 1000123456" />
                        @error('tin')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="flex justify-end"><x-button type="submit">Save</x-button></div>
            </form>
        </section>

        {{-- Taxes and levies --}}
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">Taxes &amp; levies that apply to you</h2>
                <p class="text-xs text-gray-500">Switch on the ones your properties attract. Leave a figure blank to use {{ config('app.name') }}'s default (shown in grey); change it where your council or URA charges differently. Record what you pay under <a href="{{ route('books.taxes') }}" class="underline hover:text-orange-600">Taxes &amp; levies</a>, not as expenses, so nothing is counted twice.</p>
            </div>

            @if($errors->has('taxes.*'))
                <div class="mx-6 mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first('taxes.*') }}</div>
            @endif

            <form method="POST" action="{{ route('books.settings.taxes') }}">
                @csrf @method('PUT')
                <div class="divide-y divide-gray-100">
                    @foreach($templates as $template)
                        @php
                            $targets = $template->perEstate() ? $estates->map(fn ($e) => [$e->id, $e->name]) : collect([[0, null]]);
                        @endphp
                        <div class="px-6 py-5">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                                <h3 class="text-sm font-semibold text-gray-900">{{ $template->name }}</h3>
                                <p class="text-xs text-gray-500">{{ $template->authority }}{{ $template->perEstate() ? ' · per property' : '' }}{{ $template->deductible ? ' · deductible expense' : '' }}</p>
                            </div>
                            @if($template->description)<p class="mt-1 max-w-3xl text-xs text-gray-500">{{ $template->description }}</p>@endif

                            @if($template->method === 'bands' && !empty($template->params['bands']))
                                <details class="mt-2 max-w-md text-xs text-gray-500">
                                    <summary class="cursor-pointer font-medium text-gray-600 hover:text-orange-600">Bands (average monthly income → annual amount)</summary>
                                    <table class="mt-2 w-full">
                                        @foreach($template->params['bands'] as $band)
                                            <tr class="border-b border-gray-50"><td class="py-1">{{ $fmt($band['min']) }} – {{ $band['max'] === null ? 'and above' : $fmt($band['max']) }}</td><td class="py-1 text-right text-gray-900">{{ $fmt($band['amount']) }}</td></tr>
                                        @endforeach
                                    </table>
                                </details>
                            @endif

                            @if($targets->isEmpty())
                                <p class="mt-3 text-xs text-gray-400">Add an estate first; this is set per property.</p>
                            @endif

                            <div class="mt-3 space-y-2">
                                @foreach($targets as [$estateId, $estateName])
                                    @php
                                        $tax = $mine[$template->id.':'.$estateId] ?? null;
                                        $base = "taxes[{$template->id}][{$estateId}]";
                                        $on = (bool) old("taxes.{$template->id}.{$estateId}.enabled", $tax?->is_enabled ?? false);
                                    @endphp
                                    <div x-data="{ on: {{ $on ? 'true' : 'false' }} }" class="rounded-xl border p-3 transition" :class="on ? 'border-orange-200 bg-orange-50/40' : 'border-gray-200'">
                                        <label class="flex cursor-pointer items-center gap-3">
                                            <input type="hidden" name="{{ $base }}[enabled]" value="0">
                                            <input type="checkbox" name="{{ $base }}[enabled]" value="1" x-model="on" class="rounded text-orange-500 focus:ring-orange-500" @checked($on)>
                                            <span class="text-sm font-medium text-gray-800">{{ $estateName ?? 'This applies to me' }}</span>
                                        </label>
                                        <div x-show="on" x-cloak class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                            @foreach($fields($template) as [$key, $fieldLabel, $kind, $default])
                                                <div>
                                                    <x-label>{{ $fieldLabel }}</x-label>
                                                    <div class="relative">
                                                        <x-input name="{{ $base }}[settings][{{ $key }}]" inputmode="decimal"
                                                            value="{{ old('taxes.'.$template->id.'.'.$estateId.'.settings.'.$key, $num($tax?->settings[$key] ?? null)) }}"
                                                            placeholder="{{ $default === null ? '' : ($kind === 'percent' ? $num($default) : $fmt($default)) }}" />
                                                        @if($kind === 'percent')<span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">%</span>@endif
                                                    </div>
                                                </div>
                                            @endforeach
                                            <div class="sm:col-span-2 lg:col-span-2">
                                                <x-label>Notes <span class="font-normal text-gray-400">(e.g. plot, council account)</span></x-label>
                                                <x-input name="{{ $base }}[notes]" value="{{ old('taxes.'.$template->id.'.'.$estateId.'.notes', $tax?->notes) }}" maxlength="300" />
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-col gap-3 border-t border-gray-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-gray-400">Defaults are {{ config('app.name') }}'s understanding of current Ugandan rates. They are estimates; confirm with URA and your council.</p>
                    <x-button type="submit">Save taxes</x-button>
                </div>
            </form>
        </section>
    </div>
</x-app-layout>
