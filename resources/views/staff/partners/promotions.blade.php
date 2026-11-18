<x-staff-layout>
    <x-slot name="header">Promotions</x-slot>

    <div class="max-w-7xl mx-auto space-y-6" x-data="{ form: null, codeModal: false }">
        @include('staff.partners._nav')

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500">A promotion takes money off a landlord's first paid subscription periods. Landlords get one through a partner's code or agent number, or a campaign code.</p>
            @staffcan('promotions.manage')<x-button type="button" @click="form = { is_active: true, discount_type: 'percent', funded_by: 'primora', periods: 1, plan_ids: [], cycles: [] }">+ New promotion</x-button>@endstaffcan
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            @if($promotions->isEmpty())
                <p class="px-6 py-12 text-center text-sm text-gray-500">No promotions yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-600">
                            <tr>
                                <th class="px-5 py-3 text-left font-semibold">Promotion</th>
                                <th class="px-5 py-3 text-left font-semibold">Applies to</th>
                                <th class="px-5 py-3 text-left font-semibold">Paid by</th>
                                <th class="px-5 py-3 text-right font-semibold">Landlords</th>
                                <th class="px-5 py-3 text-right font-semibold">Discount given</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($promotions as $p)
                                @php $u = $usage[$p->id] ?? null; @endphp
                                <tr class="{{ $p->isRunning() ? '' : 'opacity-60' }}">
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-gray-900">{{ $p->name }} @unless($p->isRunning())<x-badge color="gray">Not running</x-badge>@endunless</p>
                                        <p class="text-xs text-gray-500">{{ $p->summary() }}{{ $p->description ? ' · '.$p->description : '' }}</p>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-600">
                                        {{ $p->plan_ids ? $plans->whereIn('id', $p->plan_ids)->pluck('name')->join(', ') : 'All plans' }}
                                        · {{ $p->cycles ? collect($p->cycles)->map(fn ($c) => $cycles[$c]['label'] ?? $c)->join(', ') : 'any cycle' }}
                                        <span class="block text-gray-400">{{ $p->starts_on ? 'From '.$p->starts_on->format('d M Y') : '' }}{{ $p->ends_on ? ' to '.$p->ends_on->format('d M Y') : '' }}{{ $p->max_landlords ? ' · max '.$p->max_landlords.' landlords' : '' }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-xs text-gray-600">{{ ['primora' => 'Primora', 'partner' => 'Partner', 'split' => 'Split '.(100 - $p->partner_share_percent).'/'.$p->partner_share_percent][$p->funded_by] ?? $p->funded_by }}</td>
                                    <td class="px-5 py-3 text-right">{{ $u->landlords ?? 0 }}<span class="block text-xs text-gray-400">{{ $u->payments ?? 0 }} payments</span></td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap">UGX {{ number_format((float) ($u->discount ?? 0)) }}@if(($u->partner_share ?? 0) > 0)<span class="block text-xs text-gray-400">partners: {{ number_format((float) $u->partner_share) }}</span>@endif</td>
                                    <td class="px-5 py-3 text-right">
                                        @staffcan('promotions.manage')
                                            <button type="button" class="text-xs text-gray-400 hover:text-orange-600" @click="form = {{ Js::from(['id' => $p->id] + $p->only(['name', 'description', 'discount_type', 'discount_value', 'periods', 'max_landlords', 'funded_by', 'partner_share_percent', 'is_active']) + ['plan_ids' => array_map('strval', $p->plan_ids ?? []), 'cycles' => $p->cycles ?? [], 'starts_on' => $p->starts_on?->toDateString(), 'ends_on' => $p->ends_on?->toDateString()]) }}">Edit</button>
                                        @endstaffcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Campaign codes (no partner) --}}
        <section class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-gray-900">Primora campaign codes</h3>
                    <p class="text-xs text-gray-500">Codes for Primora's own campaigns (e.g. LAUNCH20): a discount only, with no partner.</p>
                </div>
                @staffcan('promotions.manage')@if($promotions->isNotEmpty())<button type="button" @click="codeModal = true" class="text-sm font-medium text-orange-600 hover:text-orange-700">+ Add code</button>@endif @endstaffcan
            </div>
            @forelse($campaignCodes as $c)
                <div class="px-5 py-3 text-sm flex items-center justify-between gap-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }} {{ $c->is_active ? '' : 'opacity-60' }}">
                    <div><span class="font-mono font-semibold text-gray-900">{{ $c->code }}</span> <span class="text-xs text-gray-500">{{ $c->label }} · {{ $c->promotion?->name }} · {{ $c->landlords_count }} landlords</span></div>
                    @staffcan('promotions.manage')
                        <form method="POST" action="{{ route('staff.promotions.codes.toggle', $c) }}">@csrf<button type="submit" class="text-xs {{ $c->is_active ? 'text-gray-400 hover:text-red-600' : 'text-orange-600' }}">{{ $c->is_active ? 'Switch off' : 'Switch on' }}</button></form>
                    @endstaffcan
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-gray-500">None.</p>
            @endforelse
        </section>

        @staffcan('promotions.manage')
            <template x-if="form">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) form = null })">
                    <x-modal title="Promotion" maxWidth="2xl">
                        <form method="POST" :action="form.id ? '{{ url('staff/promotions') }}/' + form.id : '{{ route('staff.promotions.store') }}'" class="space-y-4">
                            @csrf
                            <template x-if="form.id"><input type="hidden" name="_method" value="PUT"></template>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div><x-label :required="true">Name</x-label><x-input name="name" x-model="form.name" maxlength="120" placeholder="e.g. Agent launch offer" required /></div>
                                <div><x-label>Description</x-label><x-input name="description" x-model="form.description" maxlength="300" /></div>
                                <div>
                                    <x-label :required="true">Discount</x-label>
                                    <div class="flex gap-2">
                                        <select name="discount_type" x-model="form.discount_type" class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                                            <option value="percent">% off</option><option value="fixed">UGX off</option>
                                        </select>
                                        <x-input type="number" name="discount_value" x-model="form.discount_value" min="1" step="0.01" required />
                                    </div>
                                </div>
                                <div>
                                    <x-label :required="true">For how many paid periods</x-label>
                                    <x-input type="number" name="periods" x-model="form.periods" min="1" max="36" required />
                                    <p class="mt-1 text-xs text-gray-500">e.g. 3 = the first three payments (months, quarters or years).</p>
                                </div>
                                <div>
                                    <x-label>Plans</x-label>
                                    <div class="space-y-1 text-sm">
                                        @foreach($plans as $plan)<label class="flex items-center gap-2"><input type="checkbox" name="plan_ids[]" value="{{ $plan->id }}" x-model="form.plan_ids" class="rounded border-gray-300 text-orange-600"> {{ $plan->name }}</label>@endforeach
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">None ticked = every plan.</p>
                                </div>
                                <div>
                                    <x-label>Billing cycles</x-label>
                                    <div class="space-y-1 text-sm">
                                        @foreach($cycles as $key => $c)<label class="flex items-center gap-2"><input type="checkbox" name="cycles[]" value="{{ $key }}" x-model="form.cycles" class="rounded border-gray-300 text-orange-600"> {{ $c['label'] }}</label>@endforeach
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">None ticked = any cycle.</p>
                                </div>
                                <div><x-label>Starts</x-label><x-input type="date" name="starts_on" x-model="form.starts_on" /></div>
                                <div><x-label>Ends</x-label><x-input type="date" name="ends_on" x-model="form.ends_on" /></div>
                                <div><x-label>Most landlords</x-label><x-input type="number" name="max_landlords" x-model="form.max_landlords" min="1" placeholder="No limit" /></div>
                                <div>
                                    <x-label :required="true">Who pays for the discount</x-label>
                                    <select name="funded_by" x-model="form.funded_by" class="block w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                                        @foreach(\App\Models\Promotion::FUNDERS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                    </select>
                                    <div x-show="form.funded_by === 'split'" class="mt-2 flex items-center gap-2 text-sm">
                                        Partner pays <x-input type="number" name="partner_share_percent" x-model="form.partner_share_percent" min="0" max="100" step="0.01" class="w-24" /> %
                                    </div>
                                </div>
                            </div>
                            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" x-model="form.is_active" class="rounded border-gray-300 text-orange-600"> Running (landlords can get it)</label>
                            <p class="text-xs text-gray-500">Primora's share is recorded in the books as Promotional discounts. A partner's share is taken off what Primora owes them.</p>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Save</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>

            <template x-if="codeModal">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) codeModal = false })">
                    <x-modal title="Add a campaign code">
                        <form method="POST" action="{{ route('staff.promotions.codes.store') }}" class="space-y-4">
                            @csrf
                            <div><x-label :required="true">Code</x-label><x-input name="code" maxlength="30" placeholder="e.g. LAUNCH20" class="uppercase" required /></div>
                            <div><x-label>Campaign</x-label><x-input name="label" maxlength="120" /></div>
                            <div>
                                <x-label :required="true">Promotion</x-label>
                                <x-select name="promotion_id" required>@foreach($promotions as $pr)<option value="{{ $pr->id }}">{{ $pr->name }} · {{ $pr->summary() }}</option>@endforeach</x-select>
                            </div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Add code</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>
        @endstaffcan
    </div>
</x-staff-layout>
