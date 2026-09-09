<x-app-layout>
    <x-slot name="header">Security Deposits</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        creating: {
            lease_id: '',
            lease_text: '',
            balance: null,
            type: 'refund'
        },
        editing: {
            id: null,
            date: '',
            lease_id: '',
            type: '',
            amount: '',
            notes: ''
        },
        openCreateModal(detail) {
            this.creating.lease_id = detail.leaseId;
            this.creating.lease_text = detail.leaseText;
            this.creating.balance = detail.balance;
            this.creating.type = detail.type;
            this.createModal = true;
        },
        edit(item) {
            this.editing = { ...item };
            this.editModal = true;
            this.$nextTick(() => {
                this.$dispatch('set-search-select', { name: 'lease_id', id: item.lease_id, text: item.lease ? (item.lease.number || 'Lease #' + item.lease.id) + ' — ' + (item.lease.tenant?.name || '') : '' });
                this.$dispatch('set-money-input', { name: 'amount', value: item.amount });
            });
        },
        formatLabel(val) {
            if (!val) return '--';
            return val.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }
    }" @open-create-modal.window="openCreateModal($event.detail)">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Security Deposits</h3>
                    <p class="mt-1 text-sm text-gray-500">Track deposits, deductions, and refunds per lease.</p>
                </div>
                <div class="flex items-center gap-2">
                </div>
            </div>

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('security-deposit-ledgers.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Lease number, tenant name..." />
                    </div>
                    <div class="w-44">
                        <x-label>Tenant</x-label>
                        <x-search-select name="tenant_id" url="{{ route('search.tenants') }}" placeholder="All Tenants" />
                    </div>
                    <div class="w-44">
                        <x-label>Estate</x-label>
                        <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="All Estates" />
                    </div>
                    <div class="w-40">
                        <x-label>Type</x-label>
                        <x-select name="type">
                            <option value="">All Types</option>
                            <option value="deposit" {{ request('type') === 'deposit' ? 'selected' : '' }}>Deposit</option>
                            <option value="deduction" {{ request('type') === 'deduction' ? 'selected' : '' }}>Deduction</option>
                            <option value="refund" {{ request('type') === 'refund' ? 'selected' : '' }}>Refund</option>
                            <option value="forfeit" {{ request('type') === 'forfeit' ? 'selected' : '' }}>Forfeit</option>
                        </x-select>
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'tenant_id', 'estate_id', 'type']))
                            <a href="{{ route('security-deposit-ledgers.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                        @endif
                    </div>
                </form>
            </div>

            {{-- Summary Cards --}}
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-2xl border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Deposited</p>
                    <p class="mt-1 text-xl font-bold text-green-600">UGX {{ number_format($totals->deposit, 0) }}</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Deductions</p>
                    <p class="mt-1 text-xl font-bold text-red-600">UGX {{ number_format($totals->deductions, 0) }}</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Refunds</p>
                    <p class="mt-1 text-xl font-bold text-orange-600">UGX {{ number_format($totals->refunds, 0) }}</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Forfeits</p>
                    <p class="mt-1 text-xl font-bold text-purple-600">UGX {{ number_format($totals->forfeits, 0) }}</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Net Balance Held</p>
                    <p class="mt-1 text-xl font-bold text-gray-900">UGX {{ number_format($totals->balance, 0) }}</p>
                </div>
            </div>

            {{-- Lease Deposit Cards --}}
            @forelse($leaseDeposits as $item)
                <div class="bg-white rounded-2xl border border-gray-200 mb-4 overflow-hidden" x-data="{ expanded: false }">
                    {{-- Lease Header --}}
                    <div class="p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-3">
                                <h4 class="text-sm font-semibold text-gray-900">{{ $item->lease->tenant?->name ?? 'Unknown Tenant' }}</h4>
                                <span class="text-xs text-gray-500">{{ $item->lease->number }}</span>
                                @if($item->lease->unit)
                                    <span class="text-xs text-gray-400">{{ $item->lease->unit->estate?->name }} &mdash; {{ $item->lease->unit->name }}</span>
                                @endif
                            </div>
                            <div class="mt-3 flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                                <div class="grid grid-cols-2 sm:grid-cols-5 gap-x-6 gap-y-2">
                                    <div>
                                        <p class="text-xs text-gray-500">Deposited</p>
                                        <p class="text-sm font-medium text-green-600">UGX {{ number_format($item->deposit, 0) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500">Deductions</p>
                                        <p class="text-sm font-medium {{ $item->deductions > 0 ? 'text-red-600' : 'text-gray-400' }}">UGX {{ number_format($item->deductions, 0) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500">Refunds</p>
                                        <p class="text-sm font-medium {{ $item->refunds > 0 ? 'text-orange-600' : 'text-gray-400' }}">UGX {{ number_format($item->refunds, 0) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500">Forfeits</p>
                                        <p class="text-sm font-medium {{ $item->forfeits > 0 ? 'text-purple-600' : 'text-gray-400' }}">UGX {{ number_format($item->forfeits, 0) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-500">Available Balance</p>
                                        <p class="text-sm font-bold {{ $item->balance > 0 ? 'text-gray-900' : ($item->balance == 0 ? 'text-gray-500' : 'text-red-600') }}">UGX {{ number_format($item->balance, 0) }}</p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('security-deposit-ledgers.export', ['lease_id' => $item->lease->id]) }}" class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 text-gray-700 hover:text-gray-900 hover:border-gray-400 transition">
                                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        Export
                                    </a>
                                    @if($item->balance > 0)
                                        @php
                                            $leaseText = $item->lease->number . " — " . ($item->lease->tenant?->name ?? "");
                                        @endphp
                                        <x-button variant="secondary" type="button" class="!px-3 !py-1.5 !text-xs"
                                            data-leasetext="{{ $leaseText }}"
                                            @click="$dispatch('open-create-modal', { type: 'refund', leaseId: '{{ $item->lease->id }}', leaseText: $el.dataset.leasetext, balance: {{ (float) $item->balance }} })">
                                            Record Entry
                                        </x-button>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <button @click="expanded = !expanded" type="button" class="text-xs font-medium text-orange-500 hover:text-orange-600 transition whitespace-nowrap">
                            <span x-text="expanded ? 'Hide entries' : 'Show {{ $item->entries->count() }} entries'"></span>
                        </button>
                    </div>

                    {{-- Ledger Entries (expandable) --}}
                    <div x-show="expanded" x-cloak class="border-t border-gray-200">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100">
                                    <th class="px-5 py-2 text-left text-xs font-medium text-gray-500">Date</th>
                                    <th class="px-5 py-2 text-left text-xs font-medium text-gray-500">Type</th>
                                    <th class="px-5 py-2 text-right text-xs font-medium text-gray-500">Amount</th>
                                    <th class="px-5 py-2 text-left text-xs font-medium text-gray-500">Notes</th>
                                    <th class="px-5 py-2 text-right text-xs font-medium text-gray-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($item->entries as $entry)
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-5 py-2.5 text-gray-600">{{ $entry->date->format('M d, Y') }}</td>
                                        <td class="px-5 py-2.5">
                                            @if($entry->type === 'deposit')
                                                <x-badge color="green">Deposit</x-badge>
                                            @elseif($entry->type === 'refund')
                                                <x-badge color="orange">Refund</x-badge>
                                            @elseif($entry->type === 'forfeit')
                                                <x-badge color="purple" class="!bg-purple-900/50 !text-purple-600 !border-purple-800 border">Forfeit</x-badge>
                                            @else
                                                <x-badge color="red">Deduction</x-badge>
                                            @endif
                                        </td>
                                        <td class="px-5 py-2.5 text-right font-medium {{ $entry->type === 'deposit' ? 'text-green-600' : 'text-red-600' }}">
                                            UGX {{ $entry->type === 'deposit' ? '+' : '-' }}{{ number_format($entry->amount, 0) }}
                                        </td>
                                        <td class="px-5 py-2.5 text-gray-500 max-w-xs truncate">{{ $entry->notes ?? '--' }}</td>
                                        <td class="px-5 py-2.5 text-right">
                                            @if($entry->type !== 'deposit')
                                                <div class="flex items-center justify-end gap-2">
                                                    <x-dropdown align="right" width="48">
                                                        <x-slot name="content">
                                                            <button @click="edit({{ Js::from($entry->load('lease.tenant')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                            <form action="{{ route('security-deposit-ledgers.destroy', $entry) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this entry?', $el)">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                                            </form>
                                                        </x-slot>
                                                    </x-dropdown>
                                                </div>
                                            @else
                                                <span class="text-xs text-gray-400">Auto-tracked</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
                    <p class="text-sm text-gray-600">No security deposits found.</p>
                    <p class="text-xs text-gray-400 mt-1">Deposits are auto-created when tenants pay security deposit invoices.</p>
                </div>
            @endforelse

            @if ($leases->hasPages())
                <div class="mt-6">
                    {{ $leases->withQueryString()->links() }}
                </div>
            @endif
        </div>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => { show = true }); $watch('show', v => { if (!v) setTimeout(() => createModal = false, 300) })"
               @keydown.escape.window="createModal = false">
                <x-modal title="Log Ledger Item" maxWidth="lg">
                    <form action="{{ route('security-deposit-ledgers.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Lease</x-label>
                            <x-input type="hidden" name="lease_id" x-model="creating.lease_id" />
                            <x-input type="text" disabled x-model="creating.lease_text" class="bg-gray-50 opacity-75" />
                        </div>

                        {{-- Deposit Balance Info --}}
                        <div x-show="creating.balance !== null" x-cloak class="p-3 rounded-xl border border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <p class="text-xs text-gray-500">Available Deposit Balance</p>
                                <p class="text-sm font-bold text-green-600" x-text="'UGX ' + Number(creating.balance).toLocaleString()"></p>
                            </div>
                        </div>

                        <div>
                            <x-label :required="true">Type</x-label>
                            <x-select name="type" required x-model="creating.type">
                                <option value="deduction">Deduction</option>
                                <option value="refund">Refund</option>
                                <option value="forfeit">Forfeit</option>
                            </x-select>
                        </div>
                        <div>
                            <x-label :required="true">Amount</x-label>
                            <x-money-input name="amount" placeholder="0" required />
                        </div>
                        <div>
                            <x-label :required="true">Date</x-label>
                            <x-input type="date" name="date" required />
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" placeholder="Reason for deduction or refund..."></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Entry</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{
                show: false,
                depositBalance: null,
                loadingBalance: false,
                fetchBalance(leaseId) {
                    if (!leaseId) { this.depositBalance = null; return; }
                    this.loadingBalance = true;
                    fetch('/leases/' + leaseId + '/deposit-balance')
                        .then(r => r.json())
                        .then(d => { this.depositBalance = d.balance; this.loadingBalance = false; })
                        .catch(() => { this.depositBalance = null; this.loadingBalance = false; });
                }
            }" x-init="$nextTick(() => { show = true; fetchBalance(editing.lease_id); }); $watch('show', v => { if (!v) setTimeout(() => editModal = false, 300) })"
               @search-selected.window="if ($event.detail.name === 'lease_id') fetchBalance($event.detail.id)">
                <x-modal title="Edit Entry" maxWidth="lg">
                    <form :action="'{{ url('security-deposit-ledgers') }}/' + editing.id" method="POST" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label :required="true">Lease</x-label>
                            <x-search-select name="lease_id" url="{{ route('search.leases') }}" placeholder="Search leases..." required />
                        </div>

                        {{-- Deposit Balance Info --}}
                        <div x-show="depositBalance !== null" x-cloak class="p-3 rounded-xl border border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <p class="text-xs text-gray-500">Available Deposit Balance</p>
                                <p class="text-sm font-bold" :class="depositBalance > 0 ? 'text-green-600' : 'text-gray-500'" x-text="depositBalance !== null ? Number(depositBalance).toLocaleString() : '--'"></p>
                            </div>
                        </div>
                        <template x-if="loadingBalance">
                            <p class="text-xs text-gray-500">Loading balance...</p>
                        </template>

                        <div>
                            <x-label :required="true">Type</x-label>
                            <x-select name="type" x-model="editing.type" required>
                                <option value="deduction">Deduction</option>
                                <option value="refund">Refund</option>
                                <option value="forfeit">Forfeit</option>
                            </x-select>
                        </div>
                        <div>
                            <x-label :required="true">Amount</x-label>
                            <x-money-input name="amount" placeholder="0" required />
                        </div>
                        <div>
                            <x-label :required="true">Date</x-label>
                            <x-input type="date" name="date" x-bind:value="editing.date ? editing.date.split('T')[0] : ''" required />
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" x-model="editing.notes"></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Entry</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
