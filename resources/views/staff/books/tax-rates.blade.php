<x-staff-layout>
    <x-slot name="header">Landlord tax rates</x-slot>

    @php
        $fmt = fn ($v) => number_format((float) $v);
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $canEdit = auth('staff')->user()?->hasPermission('books.tax_rates');
    @endphp

    <div class="max-w-7xl mx-auto">
        @include('staff.books._nav')

        <div class="space-y-4">
            <p class="max-w-3xl text-sm text-gray-500">The taxes and levies landlords can switch on in their Books, with the default rates {{ config('app.name') }} suggests. A landlord's own figures (set in their Books settings) override these. Changes apply to every landlord's estimates straight away.</p>

            @if(session('error'))
                <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            @foreach($templates as $t)
                @php $p = $t->params ?? []; $editing = $errors->any() && (int) old('template_id') === $t->id; @endphp
                <section x-data="{ edit: {{ $editing ? 'true' : 'false' }} }" class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                    <div class="flex flex-col gap-3 px-6 py-5 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-sm font-semibold text-gray-900">{{ $t->name }}</h2>
                                @if(!$t->is_active)<span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600">Hidden from landlords</span>@endif
                                @if($t->deductible)<span class="rounded-full bg-green-50 px-2 py-0.5 text-[11px] font-medium text-green-700">Deductible</span>@endif
                                <span class="rounded-full bg-gray-50 px-2 py-0.5 text-[11px] font-medium text-gray-500">{{ $t->perEstate() ? 'Per property' : 'Per landlord' }}</span>
                            </div>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $t->authority }} · {{ \App\Models\TaxTemplate::METHODS[$t->method] ?? $t->method }} · used by {{ $usage[$t->id] ?? 0 }} landlord{{ ($usage[$t->id] ?? 0) === 1 ? '' : 's' }}</p>
                            <div class="mt-2 text-sm text-gray-700">
                                @switch($t->method)
                                    @case('rental_income')
                                        Individuals: <strong>{{ $num($p['individual']['rate'] ?? 0) }}%</strong> of gross rent above <strong>UGX {{ $fmt($p['individual']['threshold'] ?? 0) }}</strong> a year.
                                        Companies: <strong>{{ $num($p['company']['rate'] ?? 0) }}%</strong> of rent less expenses (capped at {{ $num($p['company']['expense_cap_percent'] ?? 0) }}% of rent).
                                        @break
                                    @case('bands')
                                        <table class="mt-1 text-xs">
                                            <tr class="text-gray-400"><th class="pr-6 text-left font-medium">Average monthly income (UGX)</th><th class="text-right font-medium">Annual amount</th></tr>
                                            @foreach($p['bands'] ?? [] as $band)
                                                <tr><td class="pr-6">{{ $fmt($band['min']) }} – {{ $band['max'] === null ? 'and above' : $fmt($band['max']) }}</td><td class="text-right">{{ $fmt($band['amount']) }}</td></tr>
                                            @endforeach
                                        </table>
                                        @break
                                    @case('percent_of_value')
                                        <strong>{{ $num($p['rate'] ?? 0) }}%</strong> of each property's rateable value a year.
                                        @break
                                    @default
                                        <strong>UGX {{ $fmt($p['amount'] ?? 0) }}</strong> a year by default{{ ((float) ($p['amount'] ?? 0)) == 0 ? ' (landlords enter their own amount)' : '' }}.
                                @endswitch
                            </div>
                        </div>
                        @if($canEdit)
                            <button type="button" @click="edit = !edit" class="shrink-0 text-sm font-medium text-orange-600 hover:text-orange-700" x-text="edit ? 'Close' : 'Edit'">Edit</button>
                        @endif
                    </div>

                    @if($canEdit)
                        <form x-show="edit" x-cloak method="POST" action="{{ route('staff.books.tax-rates.update', $t) }}" class="space-y-4 border-t border-gray-100 px-6 py-5"
                            x-data="{ bands: {{ \Illuminate\Support\Js::from(old('template_id') == $t->id ? old('params.bands', $p['bands'] ?? []) : ($p['bands'] ?? [])) }} }">
                            @csrf @method('PUT')
                            <input type="hidden" name="template_id" value="{{ $t->id }}">
                            @php $o = fn ($key, $default) => $editing ? old($key, $default) : $default; @endphp
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div><x-label>Name</x-label><x-input name="name" value="{{ $o('name', $t->name) }}" required maxlength="100" /></div>
                                <div><x-label>Paid to</x-label><x-input name="authority" value="{{ $o('authority', $t->authority) }}" required maxlength="150" /></div>
                            </div>
                            <div><x-label>Description (shown to landlords)</x-label><x-textarea name="description" rows="2" maxlength="1000">{{ $o('description', $t->description) }}</x-textarea></div>

                            @switch($t->method)
                                @case('rental_income')
                                    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                                        <div><x-label>Individual rate (%)</x-label><x-input name="params[individual][rate]" value="{{ $o('params.individual.rate', $num($p['individual']['rate'] ?? 0)) }}" inputmode="decimal" required /></div>
                                        <div><x-label>Individual threshold (UGX/yr)</x-label><x-input name="params[individual][threshold]" value="{{ $o('params.individual.threshold', $num($p['individual']['threshold'] ?? 0)) }}" inputmode="decimal" required /></div>
                                        <div><x-label>Company rate (%)</x-label><x-input name="params[company][rate]" value="{{ $o('params.company.rate', $num($p['company']['rate'] ?? 0)) }}" inputmode="decimal" required /></div>
                                        <div><x-label>Company expense cap (% of rent)</x-label><x-input name="params[company][expense_cap_percent]" value="{{ $o('params.company.expense_cap_percent', $num($p['company']['expense_cap_percent'] ?? 0)) }}" inputmode="decimal" required /></div>
                                    </div>
                                    @break
                                @case('bands')
                                    <div>
                                        <x-label>Bands (average monthly income → annual amount; leave the last "to" blank for "and above")</x-label>
                                        <div class="mt-1 space-y-2">
                                            <template x-for="(band, i) in bands" :key="i">
                                                <div class="flex items-center gap-2">
                                                    <input :name="`params[bands][${i}][min]`" x-model="band.min" inputmode="decimal" placeholder="From" class="w-36 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" required>
                                                    <span class="text-gray-400">–</span>
                                                    <input :name="`params[bands][${i}][max]`" x-model="band.max" inputmode="decimal" placeholder="and above" class="w-36 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500">
                                                    <span class="text-gray-400">→</span>
                                                    <input :name="`params[bands][${i}][amount]`" x-model="band.amount" inputmode="decimal" placeholder="Amount" class="w-36 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm focus:border-orange-500 focus:ring-1 focus:ring-orange-500" required>
                                                    <button type="button" @click="bands.splice(i, 1)" x-show="bands.length > 1" class="text-xs text-red-600 hover:text-red-700">Remove</button>
                                                </div>
                                            </template>
                                            <button type="button" @click="bands.push({ min: '', max: '', amount: '' })" class="text-xs font-medium text-orange-600 hover:text-orange-700">+ Add band</button>
                                        </div>
                                    </div>
                                    @break
                                @case('percent_of_value')
                                    <div class="w-48"><x-label>Rate (% of rateable value)</x-label><x-input name="params[rate]" value="{{ $o('params.rate', $num($p['rate'] ?? 0)) }}" inputmode="decimal" required /></div>
                                    @break
                                @default
                                    <div class="w-56"><x-label>Default annual amount (UGX)</x-label><x-input name="params[amount]" value="{{ $o('params.amount', $num($p['amount'] ?? 0)) }}" inputmode="decimal" required /></div>
                            @endswitch

                            <div class="flex flex-wrap gap-6">
                                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="rounded text-orange-500 focus:ring-orange-500" @checked($o('is_active', $t->is_active))> Offered to landlords</label>
                                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="hidden" name="deductible" value="0"><input type="checkbox" name="deductible" value="1" class="rounded text-orange-500 focus:ring-orange-500" @checked($o('deductible', $t->deductible))> Deductible expense (counts in landlords' expenses when paid)</label>
                            </div>
                            @if($errors->any() && $editing)
                                <p class="text-sm text-red-600">{{ $errors->first() }}</p>
                            @endif
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="edit = false" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</button>
                                <x-button type="submit">Save rates</x-button>
                            </div>
                        </form>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</x-staff-layout>
