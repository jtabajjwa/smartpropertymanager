<x-staff-layout>
    <x-slot name="header">Rent Collections · {{ $landlord->name }}</x-slot>

    @php
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $owed = (float) $owedNow;
        $statusColors = ['succeeded' => 'green', 'pending' => 'orange', 'indeterminate' => 'orange', 'failed' => 'red'];
    @endphp

    <div class="max-w-7xl mx-auto space-y-6" x-data="{ settleModal: false }">
        <a href="{{ route('staff.collections.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900">← Rent Collections</a>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-lg font-semibold text-gray-900">{{ $landlord->name }}</h3>
                        <x-badge :color="$landlord->collect_payments ? 'green' : 'gray'">Tenant payments {{ $landlord->collect_payments ? 'on' : 'off' }}</x-badge>
                    </div>
                    <p class="text-sm text-gray-500">{{ $landlord->contact_email }} · {{ $landlord->contact_phone }}</p>
                    <p class="mt-1 text-sm text-gray-600">Collection fee paid by <strong>{{ $landlord->pass_collection_fee ? 'tenants' : 'the landlord' }}</strong> (landlord's setting).</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @staffcan('collections.manage')
                        <form method="POST" action="{{ route('staff.collections.toggle', $landlord) }}" @submit.prevent="$store.confirm.open('{{ $landlord->collect_payments ? 'Switch off mobile money payments for the tenants of this landlord?' : 'Let the tenants of this landlord pay by mobile money into the Primora pool?' }}', $el)">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-xl border border-gray-200 text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 transition">{{ $landlord->collect_payments ? 'Switch off' : 'Switch on' }}</button>
                        </form>
                    @endstaffcan
                    @staffcan('collections.settle')
                        <x-button type="button" @click="settleModal = true">Record settlement</x-button>
                    @endstaffcan
                </div>
            </div>

            <dl class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="rounded-xl bg-gray-50 p-4"><dt class="text-xs text-gray-500 uppercase tracking-wide">Collected</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ $ugx($landlord->rent_collected) }}</dd><dd class="text-xs text-gray-500">{{ $landlord->rent_payments }} payments</dd></div>
                <div class="rounded-xl bg-gray-50 p-4"><dt class="text-xs text-gray-500 uppercase tracking-wide">Collection fees</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ $ugx($landlord->rent_fees) }}</dd></div>
                <div class="rounded-xl bg-gray-50 p-4"><dt class="text-xs text-gray-500 uppercase tracking-wide">Settled</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ $ugx($landlord->settled) }}</dd></div>
                <div class="rounded-xl {{ $owed > 0 ? 'bg-orange-50' : 'bg-gray-50' }} p-4"><dt class="text-xs text-gray-500 uppercase tracking-wide">Owed to landlord</dt><dd class="mt-1 text-lg font-bold {{ $owed > 0 ? 'text-orange-700' : 'text-gray-900' }}">{{ $ugx($owed) }}</dd></div>
            </dl>
        </div>

        {{-- Collections --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-base font-semibold text-gray-900">Tenant payments</h3></div>
            @if($collections->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">No tenant payments yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Tenant / for</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Paid</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Fee</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">To landlord</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($collections as $p)
                                @php $c = $p->context ?? []; @endphp
                                <tr>
                                    <td class="px-6 py-3 text-gray-600 whitespace-nowrap">{{ ($p->completed_at ?? $p->created_at)->format('d M Y, H:i') }}</td>
                                    <td class="px-6 py-3 text-gray-900">{{ $c['tenant_name'] ?? '—' }}<span class="block text-xs text-gray-500">{{ !empty($c['invoice_number']) ? 'Invoice '.$c['invoice_number'] : 'Advance payment' }}{{ ($c['fee_bearer'] ?? null) === 'tenant' ? ' · fee paid by tenant' : '' }}</span></td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap">{{ number_format((float) $p->amount) }}</td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap text-gray-600">{{ number_format((float) $p->fee_amount) }}</td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap font-medium">{{ $p->status === 'succeeded' ? number_format((float) $p->landlord_amount) : '—' }}</td>
                                    <td class="px-6 py-3"><x-badge :color="$statusColors[$p->status] ?? 'gray'">{{ ucfirst($p->status) }}</x-badge></td>
                                    <td class="px-6 py-3 text-xs font-mono">
                                        @staffcan('payments.view')
                                            <a href="{{ route('staff.payments.show', $p) }}" class="text-orange-600 hover:text-orange-700">{{ $p->reference }}</a>
                                        @else
                                            <span class="text-gray-500">{{ $p->reference }}</span>
                                        @endstaffcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($collections->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">{{ $collections->links() }}</div>
                @endif
            @endif
        </div>

        {{-- Settlements --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-base font-semibold text-gray-900">Settlements to the landlord</h3></div>
            @if($settlements->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">No settlements recorded yet.</p>
            @else
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Method / reference</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Recorded by</th>
                            @staffcan('collections.settle')<th class="px-6 py-3"></th>@endstaffcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($settlements as $s)
                            <tr>
                                <td class="px-6 py-3 text-gray-600 whitespace-nowrap">{{ $s->settled_on->format('d M Y') }}</td>
                                <td class="px-6 py-3 text-right font-medium whitespace-nowrap">{{ $ugx($s->amount) }}</td>
                                <td class="px-6 py-3 text-gray-700">{{ \App\Models\LandlordSettlement::METHODS[$s->method] ?? $s->method }}@if($s->reference) · <span class="font-mono text-xs">{{ $s->reference }}</span>@endif @if($s->notes)<span class="block text-xs text-gray-500">{{ $s->notes }}</span>@endif</td>
                                <td class="px-6 py-3 text-gray-600">{{ $s->recordedBy?->name ?? '—' }}</td>
                                @staffcan('collections.settle')
                                    <td class="px-6 py-3 text-right">
                                        <form method="POST" action="{{ route('staff.collections.settlements.destroy', [$landlord, $s]) }}" @submit.prevent="$store.confirm.open('Delete this settlement? The amount owed will go back up.', $el)">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Delete</button>
                                        </form>
                                    </td>
                                @endstaffcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @staffcan('collections.settle')
            <template x-if="settleModal">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) settleModal = false })">
                    <x-modal title="Record settlement to {{ $landlord->name }}">
                        <form method="POST" action="{{ route('staff.collections.settlements.store', $landlord) }}" class="space-y-4">
                            @csrf
                            <p class="text-sm text-gray-600">Money Primora paid out to the landlord from rent collected for them. Currently owed: <strong>{{ $ugx($owed) }}</strong>.</p>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <x-label :required="true">Amount (UGX)</x-label>
                                    <x-input type="number" name="amount" min="1" step="1" value="{{ old('amount', $owed > 0 ? (int) $owed : '') }}" required />
                                </div>
                                <div>
                                    <x-label :required="true">Paid on</x-label>
                                    <x-input type="date" name="settled_on" value="{{ old('settled_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <x-label :required="true">Method</x-label>
                                    <x-select name="method">
                                        @foreach(\App\Models\LandlordSettlement::METHODS as $value => $label)
                                            <option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                                <div>
                                    <x-label>Reference</x-label>
                                    <x-input name="reference" value="{{ old('reference') }}" />
                                </div>
                            </div>
                            <div>
                                <x-label>Notes</x-label>
                                <x-textarea name="notes" rows="2">{{ old('notes') }}</x-textarea>
                            </div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Record settlement</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>
        @endstaffcan
    </div>
</x-staff-layout>
