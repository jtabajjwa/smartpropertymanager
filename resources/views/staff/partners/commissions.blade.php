<x-staff-layout>
    <x-slot name="header">Commissions</x-slot>
    @php $ugx = fn ($v) => 'UGX '.number_format((float) $v); @endphp

    <div class="max-w-7xl mx-auto space-y-6" x-data="{ plan: null }">
        @include('staff.partners._nav')

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([['Owed to partners', $totals['owed'], 'Not paid out yet, after tax'], ['Available to withdraw', $totals['available'], 'Past the holding period'], ['Earned this month', $totals['month'], now()->format('F Y')], ['Tax withheld', $totals['wht'], 'All time']] as [$label, $value, $hint])
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">{{ $ugx($value) }}</p>
                    <p class="text-xs text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Settings --}}
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <h3 class="px-5 py-3.5 border-b border-gray-100 font-semibold text-gray-900">Settings</h3>
                <form method="POST" action="{{ route('staff.commissions.settings') }}" class="p-5 space-y-4">
                    @csrf
                    <div>
                        <x-label :required="true">Holding period (days)</x-label>
                        <x-input type="number" name="holding_days" value="{{ old('holding_days', $holdingDays) }}" min="0" max="180" required :disabled="! auth('staff')->user()->hasPermission('commissions.manage')" />
                        <p class="mt-1 text-xs text-gray-500">Earnings wait this long before partners can withdraw them, in case a payment is refunded.</p>
                    </div>
                    <div>
                        <x-label :required="true">Minimum payout (UGX)</x-label>
                        <x-input type="number" name="min_payout" value="{{ old('min_payout', (int) $minPayout) }}" min="0" step="1" required :disabled="! auth('staff')->user()->hasPermission('commissions.manage')" />
                    </div>
                    <div>
                        <x-label :required="true">Withholding tax (%)</x-label>
                        <x-input type="number" name="wht_percent" value="{{ old('wht_percent', $whtPercent) }}" min="0" max="100" step="0.01" required :disabled="! auth('staff')->user()->hasPermission('commissions.manage')" />
                        <p class="mt-1 text-xs text-gray-500">Taken off each commission when it's earned, and kept in Withholding tax payable. Confirm the rate with your accountant.</p>
                    </div>
                    <div>
                        <x-label>Default commission plan</x-label>
                        <x-select name="default_plan_id" :disabled="! auth('staff')->user()->hasPermission('commissions.manage')">
                            <option value="">None: partners without a plan earn nothing</option>
                            @foreach($plans->where('is_active', true) as $p)<option value="{{ $p->id }}" @selected($defaultPlan?->id === $p->id)>{{ $p->name }}</option>@endforeach
                        </x-select>
                    </div>
                    @staffcan('commissions.manage')<x-button type="submit">Save settings</x-button>@endstaffcan
                </form>
            </section>

            {{-- Plans --}}
            <section class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h3 class="font-semibold text-gray-900">Commission plans</h3>
                        <p class="text-xs text-gray-500">What partners earn on their landlords' subscription payments. Give a partner a plan on their page, or set a default.</p>
                    </div>
                    @staffcan('commissions.manage')<button type="button" @click="plan = { base: 'paid', is_active: true, later_rate_percent: 0, signup_bonus: 0 }" class="text-sm font-medium text-orange-600 hover:text-orange-700">+ New plan</button>@endstaffcan
                </div>
                @forelse($plans as $p)
                    <div class="px-5 py-3 text-sm flex items-center justify-between gap-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }} {{ $p->is_active ? '' : 'opacity-60' }}">
                        <div>
                            <p class="font-medium text-gray-900">{{ $p->name }} @if($defaultPlan?->id === $p->id)<x-badge color="orange">Default</x-badge>@endif @unless($p->is_active)<x-badge color="gray">Off</x-badge>@endunless</p>
                            <p class="text-xs text-gray-500">{{ $p->summary() }} · on {{ mb_strtolower(\App\Models\CommissionPlan::BASES[$p->base] ?? $p->base) }} · {{ $p->partners_count }} {{ \Illuminate\Support\Str::plural('partner', $p->partners_count) }}</p>
                        </div>
                        @staffcan('commissions.manage')
                            <button type="button" class="text-xs text-gray-400 hover:text-orange-600" @click="plan = {{ Js::from(['id' => $p->id] + $p->only(['name', 'base', 'rate_percent', 'months', 'later_rate_percent', 'signup_bonus', 'is_active'])) }}">Edit</button>
                        @endstaffcan
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-gray-500">No plans yet. Partners earn nothing until they have one.</p>
                @endforelse
            </section>
        </div>

        @if($top->isNotEmpty())
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <h3 class="px-5 py-3.5 border-b border-gray-100 font-semibold text-gray-900">Owed to partners</h3>
                @foreach($top as $row)
                    <a href="{{ route('staff.partners.show', $row['partner']) }}" class="px-5 py-2.5 flex justify-between text-sm hover:bg-gray-50 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <span class="text-gray-900">{{ $row['partner']->name }} <span class="text-xs text-gray-400">#{{ $row['partner']->number }}</span></span>
                        <span class="font-medium">{{ $ugx($row['owed']) }}</span>
                    </a>
                @endforeach
            </section>
        @endif

        @staffcan('commissions.manage')
            <template x-if="plan">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) plan = null })">
                    <x-modal title="Commission plan" maxWidth="2xl">
                        <form method="POST" :action="plan.id ? '{{ url('staff/commissions/plans') }}/' + plan.id : '{{ route('staff.commissions.plans.store') }}'" class="space-y-4">
                            @csrf
                            <template x-if="plan.id"><input type="hidden" name="_method" value="PUT"></template>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="sm:col-span-2"><x-label :required="true">Name</x-label><x-input name="name" x-model="plan.name" maxlength="120" placeholder="e.g. Standard agent" required /></div>
                                <div class="sm:col-span-2">
                                    <x-label :required="true">Calculated on</x-label>
                                    <select name="base" x-model="plan.base" class="block w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                                        @foreach(\App\Models\CommissionPlan::BASES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                    </select>
                                </div>
                                <div><x-label :required="true">Share of each payment (%)</x-label><x-input type="number" name="rate_percent" x-model="plan.rate_percent" min="0" max="100" step="0.01" required /></div>
                                <div>
                                    <x-label>For how many months</x-label>
                                    <x-input type="number" name="months" x-model="plan.months" min="1" max="240" placeholder="Always" />
                                    <p class="mt-1 text-xs text-gray-500">From the landlord's first payment. Empty = for as long as they pay.</p>
                                </div>
                                <div x-show="plan.months"><x-label>Then (%)</x-label><x-input type="number" name="later_rate_percent" x-model="plan.later_rate_percent" min="0" max="100" step="0.01" /><p class="mt-1 text-xs text-gray-500">0 = nothing after that.</p></div>
                                <div><x-label>One-off bonus per landlord (UGX)</x-label><x-input type="number" name="signup_bonus" x-model="plan.signup_bonus" min="0" step="1" /><p class="mt-1 text-xs text-gray-500">On the landlord's first payment.</p></div>
                            </div>
                            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" x-model="plan.is_active" class="rounded border-gray-300 text-orange-600"> In use</label>
                            <p class="text-xs text-gray-500">Changes apply to payments from now on. Withholding tax ({{ $whtPercent }}%) comes off what partners earn.</p>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Save</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>
        @endstaffcan
    </div>
</x-staff-layout>
