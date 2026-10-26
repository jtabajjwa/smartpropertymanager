<x-staff-layout>
    <x-slot name="header">Rent Collections</x-slot>

    @php $ugx = fn ($v) => 'UGX '.number_format((float) $v); @endphp

    <div class="py-2" x-data="{
        tierModal: false,
        tier: {},
        newTier() { this.tier = { id: null, min_amount: '', max_amount: '', fixed_fee: '', percent_fee: 0 }; this.tierModal = true; },
        editTier(t) { this.tier = { ...t }; this.tierModal = true; },
    }">
        <div class="max-w-7xl mx-auto space-y-6">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Rent Collections</h3>
                <p class="mt-1 text-sm text-gray-500">Rent tenants pay by mobile money into the Primora pool, owed to their landlords until settled.</p>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <x-stat-card label="Landlords switched on" :value="number_format($enabledCount)" />
                <x-stat-card label="Collected (all time)" :value="$ugx($totals['collected'])">
                    <x-slot name="sub">{{ $ugx($totals['month']) }} this month</x-slot>
                </x-stat-card>
                <x-stat-card label="Collection fees" :value="$ugx($totals['fees'])">
                    <x-slot name="sub">Covers Yo! inbound charges</x-slot>
                </x-stat-card>
                <x-stat-card label="Settled to landlords" :value="$ugx($totals['settled'])" />
                <div class="bg-white rounded-2xl shadow-sm border {{ $totals['owed'] - $totals['settled'] > 0 ? 'border-orange-300' : 'border-gray-200' }} p-5">
                    <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Owed to landlords</p>
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ $ugx($totals['owed'] - $totals['settled']) }}</p>
                    <p class="mt-1 text-xs text-gray-500">Not yet settled</p>
                </div>
            </div>

            {{-- Landlords --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Landlords</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ request('show') === 'all' ? 'All landlords.' : 'Landlords switched on, or with collections.' }}</p>
                    </div>
                    <form method="GET" class="flex flex-wrap items-end gap-3">
                        <x-input name="search" value="{{ request('search') }}" placeholder="Search landlord…" class="w-56" />
                        <x-select name="show" class="w-44">
                            <option value="">Switched on / active</option>
                            <option value="all" @selected(request('show') === 'all')>All landlords</option>
                        </x-select>
                        <x-button type="submit">Filter</x-button>
                    </form>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Landlord</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Tenant payments</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Collected</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Fees</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Settled</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Owed</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($landlords as $landlord)
                                @php $owed = (float) $landlord->rent_owed - (float) $landlord->settled; @endphp
                                <tr>
                                    <td class="px-5 py-3">
                                        <a href="{{ route('staff.collections.show', $landlord) }}" class="font-medium text-gray-900 hover:text-orange-600">{{ $landlord->name }}</a>
                                        <span class="block text-xs text-gray-400">{{ $landlord->rent_payments }} {{ Str::plural('payment', $landlord->rent_payments) }}@if($landlord->last_collected_at) · last {{ \Carbon\Carbon::parse($landlord->last_collected_at)->format('d M Y') }}@endif</span>
                                    </td>
                                    <td class="px-5 py-3">
                                        <x-badge :color="$landlord->collect_payments ? 'green' : 'gray'">{{ $landlord->collect_payments ? 'On' : 'Off' }}</x-badge>
                                        @if($landlord->collect_payments)<span class="block mt-1 text-xs text-gray-500">Fee paid by {{ $landlord->pass_collection_fee ? 'tenant' : 'landlord' }}</span>@endif
                                    </td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap">{{ number_format((float) $landlord->rent_collected) }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap text-gray-600">{{ number_format((float) $landlord->rent_fees) }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap text-gray-600">{{ number_format((float) $landlord->settled) }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap font-semibold {{ $owed > 0 ? 'text-orange-600' : 'text-gray-900' }}">{{ number_format($owed) }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap">
                                        @staffcan('collections.manage')
                                            <form method="POST" action="{{ route('staff.collections.toggle', $landlord) }}" class="inline" @submit.prevent="$store.confirm.open('{{ $landlord->collect_payments ? 'Switch off mobile money payments for the tenants of this landlord?' : 'Let the tenants of this landlord pay by mobile money into the Primora pool?' }}', $el)">
                                                @csrf
                                                <button type="submit" class="text-sm font-medium {{ $landlord->collect_payments ? 'text-red-600 hover:text-red-700' : 'text-orange-600 hover:text-orange-700' }}">{{ $landlord->collect_payments ? 'Switch off' : 'Switch on' }}</button>
                                            </form>
                                        @endstaffcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-5 py-10 text-center text-gray-500">No landlords collect rent through Primora yet. Choose "All landlords" to switch one on.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($landlords->hasPages())
                    <div class="px-5 py-4 border-t border-gray-200">{{ $landlords->links() }}</div>
                @endif
            </div>

            {{-- Fee bands --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Collection fee bands</h3>
                        <p class="mt-0.5 text-sm text-gray-500">The charge per tenant payment, by amount (match Yo!'s inbound charges). Landlords bear it unless they choose to pass it to tenants.</p>
                    </div>
                    @staffcan('collections.manage')<x-button type="button" @click="newTier()">Add band</x-button>@endstaffcan
                </div>
                @if($tiers->isEmpty())
                    <p class="px-6 py-8 text-center text-sm text-gray-500">No fee bands yet, so no fee is charged on tenant payments.</p>
                @else
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount paid (UGX)</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Fixed fee</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">+ Percent</th>
                                @staffcan('collections.manage')<th class="px-6 py-3"></th>@endstaffcan
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($tiers as $t)
                                <tr>
                                    <td class="px-6 py-3 text-gray-900">{{ $t->label() }}</td>
                                    <td class="px-6 py-3 text-right">{{ number_format((float) $t->fixed_fee) }}</td>
                                    <td class="px-6 py-3 text-right text-gray-600">{{ (float) $t->percent_fee ? rtrim(rtrim(number_format((float) $t->percent_fee, 3), '0'), '.').'%' : '—' }}</td>
                                    @staffcan('collections.manage')
                                        <td class="px-6 py-3 text-right whitespace-nowrap">
                                            <button type="button" class="text-sm font-medium text-gray-600 hover:text-orange-600" @click="editTier({{ Js::from(['id' => $t->id, 'min_amount' => (float) $t->min_amount, 'max_amount' => $t->max_amount === null ? '' : (float) $t->max_amount, 'fixed_fee' => (float) $t->fixed_fee, 'percent_fee' => (float) $t->percent_fee]) }})">Edit</button>
                                            <form method="POST" action="{{ route('staff.collections.tiers.destroy', $t) }}" class="inline" @submit.prevent="$store.confirm.open('Delete this fee band?', $el)">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="ml-3 text-sm font-medium text-red-600 hover:text-red-700">Delete</button>
                                            </form>
                                        </td>
                                    @endstaffcan
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <template x-if="tierModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) tierModal = false })">
                <x-modal title="Collection fee band">
                    <form method="POST" :action="tier.id ? '{{ url('staff/collections/tiers') }}/' + tier.id : '{{ route('staff.collections.tiers.store') }}'" class="space-y-4">
                        @csrf
                        <template x-if="tier.id"><input type="hidden" name="_method" value="PUT"></template>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">From (UGX)</x-label>
                                <x-input type="number" name="min_amount" min="0" step="1" x-model="tier.min_amount" required />
                            </div>
                            <div>
                                <x-label>To (UGX)</x-label>
                                <x-input type="number" name="max_amount" min="0" step="1" x-model="tier.max_amount" placeholder="and above" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Fixed fee (UGX)</x-label>
                                <x-input type="number" name="fixed_fee" min="0" step="1" x-model="tier.fixed_fee" required />
                            </div>
                            <div>
                                <x-label>Plus percent</x-label>
                                <x-input type="number" name="percent_fee" min="0" max="20" step="0.001" x-model="tier.percent_fee" />
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                            <x-button type="submit">Save</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-staff-layout>
