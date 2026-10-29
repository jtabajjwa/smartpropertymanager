<x-app-layout>
    <x-slot name="header">Leases</x-slot>

    <x-tip id="leases" title="A lease starts the rent invoicing">
        Choose the tenant, the unit, the rent and how often it’s due. When you save it, Primora raises the first rent invoice (and the security deposit, if any) straight away, then each next one on schedule.
    </x-tip>

    <div class="py-8" x-data="{
        createModal: {{ request()->has('new') ? 'true' : 'false' }},
        editModal: false,
        viewModal: false,
        viewing: {},
        editing: {
            id: null,
            number: '',
            tenant_id: '',
            unit_id: '',
            status: '',
            start_date: '',
            end_date: '',
            rent_amount: '',
            billing_cycle: '',
            security_deposit: '',
            required_upfront_periods: '',
            agreement_file: '',
            next_invoice_date: ''
        },
        view(item) {
            this.viewing = { ...item };
            this.viewModal = true;
        },
        formatLabel(val) {
            if (!val) return '--';
            return val.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        },
        edit(item) {
            this.editing = { ...item };
            this.editModal = true;
            if (!item.locked) {
                this.$nextTick(() => {
                    this.$dispatch('set-search-select', { name: 'tenant_id', id: item.tenant_id, text: item.tenant?.name || '' });
                    this.$dispatch('set-search-select', { name: 'unit_id', id: item.unit_id, text: item.unit ? (item.unit.name + (item.unit.estate ? ' (' + item.unit.estate.name + ')' : '')) : '' });
                    this.$dispatch('set-money-input', { name: 'rent_amount', value: item.rent_amount });
                    this.$dispatch('set-money-input', { name: 'security_deposit', value: item.security_deposit });
                });
            }
        },
        formatDate(val) {
            if (!val) return '';
            return val.split('T')[0];
        },
        agreementModal: false,
        agreementLease: null,
        agreementTemplateId: '{{ $leaseTemplates->firstWhere('is_default', true)?->id }}',
        openAgreementModal(lease) {
            this.agreementLease = lease;
            this.agreementModal = true;
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">All Leases</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage lease agreements for your tenants.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a hx-boost="false" href="{{ route('leases.export', request()->query()) }}" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:text-gray-900 hover:border-gray-400 transition">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Export
                    </a>
                    <x-button @click="createModal = true" type="button">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Lease
                    </x-button>
                </div>
            </div>

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('leases.index') }}" class="flex flex-wrap items-end gap-4">
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
                        <x-label>Status</x-label>
                        <x-select name="status">
                            <option value="">All Statuses</option>
                            <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="terminated" {{ request('status') === 'terminated' ? 'selected' : '' }}>Terminated</option>
                            <option value="evicted" {{ request('status') === 'evicted' ? 'selected' : '' }}>Evicted</option>
                        </x-select>
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'tenant_id', 'estate_id', 'status']))
                            <a href="{{ route('leases.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                        @endif
                    </div>
                </form>
            </div>

            {{-- Table (md and up) --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Number</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Tenant</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Unit</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Period</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Next Invoice</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Rent</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($leases as $lease)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $lease->number }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $lease->tenant?->name ?? '--' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">
                                        {{ $lease->unit?->name ?? '--' }}
                                        @if($lease->unit?->estate)
                                            <span class="text-xs text-gray-600">({{ $lease->unit->estate->name }})</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        <x-badge :color="match($lease->status) { 'active' => 'green', 'terminated' => 'red', 'evicted' => 'red', default => 'gray' }">{{ ucfirst($lease->status) }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4">
                                        <p class="text-sm text-gray-600">{{ $lease->start_date?->format('M d, Y') }}</p>
                                        <p class="text-xs text-gray-600">to {{ $lease->end_date?->format('M d, Y') ?? 'ongoing' }}</p>
                                        @if($lease->status === 'active' && $lease->daysRemaining() !== null)
                                            <p class="text-xs {{ $lease->daysRemaining() <= 30 ? 'text-red-500 font-medium' : 'text-gray-600' }}">{{ $lease->daysRemaining() }} days left</p>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        <p class="text-sm text-gray-900">{{ $lease->next_invoice_date ? $lease->next_invoice_date->format('M d, Y') : '--' }}</p>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">UGX {{ number_format($lease->rent_amount, 0) }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({ ...{{ Js::from($lease) }}, locked: {{ $lease->invoices_count > 0 ? 'true' : 'false' }} })" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                                    <button @click="openAgreementModal({{ Js::from($lease) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition">Generate Agreement</button>
                                                    @if(!in_array($lease->status, ['terminated', 'evicted']))
                                                        <button @click="edit({ ...{{ Js::from($lease) }}, locked: {{ $lease->invoices_count > 0 ? 'true' : 'false' }} })" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    @endif
                                                    @if($lease->invoices_count === 0)
                                                        <form action="{{ route('leases.destroy', $lease) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this lease?', $el)">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                                        </form>
                                                    @endif
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No leases found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($leases as $lease)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 break-words">{{ $lease->number }}</p>
                                    <p class="mt-0.5 text-sm text-gray-600 break-words">{{ $lease->tenant?->name ?? '--' }}</p>
                                    <p class="text-sm text-gray-600">
                                        {{ $lease->unit?->name ?? '--' }}
                                        @if($lease->unit?->estate)
                                            <span class="text-xs text-gray-600">({{ $lease->unit->estate->name }})</span>
                                        @endif
                                    </p>
                                </div>
                                <x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        <button @click="view({ ...{{ Js::from($lease) }}, locked: {{ $lease->invoices_count > 0 ? 'true' : 'false' }} })" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                        <button @click="openAgreementModal({{ Js::from($lease) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition">Generate Agreement</button>
                                        @if(!in_array($lease->status, ['terminated', 'evicted']))
                                            <button @click="edit({ ...{{ Js::from($lease) }}, locked: {{ $lease->invoices_count > 0 ? 'true' : 'false' }} })" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                        @endif
                                        @if($lease->invoices_count === 0)
                                            <form action="{{ route('leases.destroy', $lease) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this lease?', $el)">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                            </form>
                                        @endif
                                    </x-slot>
                                </x-dropdown>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-badge :color="match($lease->status) { 'active' => 'green', 'terminated' => 'red', 'evicted' => 'red', default => 'gray' }">{{ ucfirst($lease->status) }}</x-badge>
                            </div>
                            <div class="mt-3 text-sm text-gray-600 space-y-1">
                                <p>
                                    <span class="text-gray-500">Period:</span> {{ $lease->start_date?->format('M d, Y') }} to {{ $lease->end_date?->format('M d, Y') ?? 'ongoing' }}
                                    @if($lease->status === 'active' && $lease->daysRemaining() !== null)
                                        <span class="{{ $lease->daysRemaining() <= 30 ? 'text-red-500 font-medium' : '' }}">({{ $lease->daysRemaining() }} days left)</span>
                                    @endif
                                </p>
                                <p><span class="text-gray-500">Next Invoice:</span> {{ $lease->next_invoice_date ? $lease->next_invoice_date->format('M d, Y') : '--' }}</p>
                                <p><span class="text-gray-500">Rent:</span> UGX {{ number_format($lease->rent_amount, 0) }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No leases found.</p>
                        </div>
                    @endforelse
                </div>

                @if ($leases->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $leases->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Lease Details" maxWidth="lg">
                    <div class="space-y-5">
                        <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Number</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.number || '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Tenant</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.tenant?.name || '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Unit</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="(viewing.unit?.name || '--') + (viewing.unit?.estate ? ' (' + viewing.unit.estate.name + ')' : '')"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Status</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="formatLabel(viewing.status)"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Start Date</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.start_date ? viewing.start_date.split('T')[0] : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">End Date</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.end_date ? viewing.end_date.split('T')[0] : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Rent Amount</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.rent_amount ? 'UGX ' + Number(viewing.rent_amount).toLocaleString() : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Security Deposit</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.security_deposit ? 'UGX ' + Number(viewing.security_deposit).toLocaleString() : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Billing Cycle</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="formatLabel(viewing.billing_cycle)"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Required Upfront Periods</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.required_upfront_periods || '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Next Invoice Date</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.next_invoice_date ? viewing.next_invoice_date.split('T')[0] : '--'"></p>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Agreement File</p>
                            <template x-if="viewing.agreement_file">
                                <a :href="'/storage/' + viewing.agreement_file" target="_blank">
                                    <img :src="'/storage/' + viewing.agreement_file" alt="Agreement" class="w-full h-32 object-cover rounded-xl border border-gray-200 hover:border-orange-500 transition" />
                                </a>
                            </template>
                            <template x-if="!viewing.agreement_file">
                                <div class="w-full h-32 rounded-xl border border-gray-200 bg-gray-50 flex items-center justify-center">
                                    <p class="text-xs text-gray-500">No agreement file</p>
                                </div>
                            </template>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="viewModal = false">Close</x-button>
                            <x-button type="button" @click="viewModal = false; edit(viewing)">Edit</x-button>
                        </div>
                    </div>
                </x-modal>
            </div>
        </template>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Lease" maxWidth="2xl">
                    <form action="{{ route('leases.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
                        @csrf
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Tenant</x-label>
                                <x-search-select name="tenant_id" url="{{ route('search.tenants') }}" placeholder="Search tenants..." required />
                            </div>
                            <div>
                                <x-label :required="true">Unit</x-label>
                                <x-search-select name="unit_id" url="{{ route('search.units') }}?available=1" placeholder="Search available units..." required />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Start Date</x-label>
                                <x-input type="date" name="start_date" required />
                            </div>
                            <div>
                                <x-label>End Date</x-label>
                                <x-input type="date" name="end_date" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Rent Amount</x-label>
                                <x-money-input name="rent_amount" required />
                            </div>
                            <div>
                                <x-label :required="true">Billing Cycle</x-label>
                                <x-select name="billing_cycle" required>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="fortnightly">Fortnightly</option>
                                    <option value="monthly" selected>Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="yearly">Yearly</option>
                                </x-select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label>Security Deposit</x-label>
                                <x-money-input name="security_deposit" />
                            </div>
                            <div>
                                <x-label>Required Upfront Periods</x-label>
                                <x-input type="number" name="required_upfront_periods" placeholder="1" />
                            </div>
                        </div>
                        <div>
                            <x-label>Agreement File</x-label>
                            <input type="file" name="agreement_file" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Lease</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Lease" maxWidth="2xl">
                    <form :action="'{{ url('leases') }}/' + editing.id" method="POST" enctype="multipart/form-data" class="space-y-5">
                        @csrf
                        @method('PUT')

                        {{-- Read-only summary (always shown for locked fields) --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 p-4 bg-gray-50 rounded-xl border border-gray-200">
                            <div>
                                <p class="text-xs text-gray-500">Number</p>
                                <p class="text-sm text-gray-900 font-medium" x-text="editing.number"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Tenant</p>
                                <p class="text-sm text-gray-900" x-text="editing.tenant?.name || '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Unit</p>
                                <p class="text-sm text-gray-900" x-text="(editing.unit?.name || '--') + (editing.unit?.estate ? ' (' + editing.unit.estate.name + ')' : '')"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Start Date</p>
                                <p class="text-sm text-gray-900" x-text="formatDate(editing.start_date) || '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Rent Amount</p>
                                <p class="text-sm text-gray-900" x-text="editing.rent_amount ? 'UGX ' + Number(editing.rent_amount).toLocaleString() : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Billing Cycle</p>
                                <p class="text-sm text-gray-900" x-text="formatLabel(editing.billing_cycle)"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Security Deposit</p>
                                <p class="text-sm text-gray-900" x-text="editing.security_deposit ? 'UGX ' + Number(editing.security_deposit).toLocaleString() : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Upfront Periods</p>
                                <p class="text-sm text-gray-900" x-text="editing.required_upfront_periods || '--'"></p>
                            </div>
                        </div>

                        {{-- Unlocked fields (tenant, unit, start, financials) — only when no invoices --}}
                        <template x-if="!editing.locked">
                            <div class="space-y-5">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">Tenant</x-label>
                                        <x-search-select name="tenant_id" url="{{ route('search.tenants') }}" placeholder="Search tenants..." required />
                                    </div>
                                    <div>
                                        <x-label :required="true">Unit</x-label>
                                        <x-search-select name="unit_id" url="{{ route('search.units') }}" placeholder="Search units..." required />
                                    </div>
                                </div>
                                <div>
                                    <x-label :required="true">Start Date</x-label>
                                    <x-input type="date" name="start_date" :x-model="'formatDate(editing.start_date)'" required />
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">Rent Amount</x-label>
                                        <x-money-input name="rent_amount" required />
                                    </div>
                                    <div>
                                        <x-label :required="true">Billing Cycle</x-label>
                                        <x-select name="billing_cycle" x-model="editing.billing_cycle" required>
                                            <option value="daily">Daily</option>
                                            <option value="weekly">Weekly</option>
                                            <option value="fortnightly">Fortnightly</option>
                                            <option value="monthly">Monthly</option>
                                            <option value="quarterly">Quarterly</option>
                                            <option value="yearly">Yearly</option>
                                        </x-select>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label>Security Deposit</x-label>
                                        <x-money-input name="security_deposit" />
                                    </div>
                                    <div>
                                        <x-label>Required Upfront Periods</x-label>
                                        <x-input type="number" name="required_upfront_periods" x-model="editing.required_upfront_periods" />
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template x-if="editing.locked">
                            <div>
                                <input type="hidden" name="tenant_id" :value="editing.tenant_id">
                                <input type="hidden" name="unit_id" :value="editing.unit_id">
                                <input type="hidden" name="start_date" :value="formatDate(editing.start_date)">
                                <input type="hidden" name="rent_amount" :value="editing.rent_amount">
                                <input type="hidden" name="billing_cycle" :value="editing.billing_cycle">
                                <input type="hidden" name="security_deposit" :value="editing.security_deposit || 0">
                                <input type="hidden" name="required_upfront_periods" :value="editing.required_upfront_periods">
                            </div>
                        </template>

                        {{-- Always editable fields --}}
                        <div>
                            <x-label :required="true">Status</x-label>
                            <x-select name="status" x-model="editing.status" required>
                                <option value="active">Active</option>
                                <option value="terminated">Terminated</option>
                                <option value="evicted">Evicted</option>
                            </x-select>
                        </div>
                        <div>
                            <x-label>End Date</x-label>
                            <x-input type="date" name="end_date" :x-model="'formatDate(editing.end_date)'" />
                        </div>
                        <div>
                            <x-label>Agreement File</x-label>
                            <template x-if="editing.agreement_file">
                                <p class="text-xs text-gray-500 mb-1">Current: <span x-text="editing.agreement_file.split('/').pop()" class="text-orange-600"></span></p>
                            </template>
                            <input type="file" name="agreement_file" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                        </div>
                        <div>
                            <x-label>Next Invoice Date</x-label>
                            <x-input type="date" name="next_invoice_date" :x-model="'formatDate(editing.next_invoice_date)'" />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Lease</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Generate Agreement Modal --}}
        <template x-if="agreementModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) agreementModal = false })">
                <x-modal title="Generate Agreement" maxWidth="md">
                    <p class="text-sm text-gray-600 mb-4">
                        Generate a filled-in agreement PDF for <span class="font-medium text-gray-900" x-text="agreementLease?.number"></span> — print it, get it signed, then upload the scanned copy on this lease.
                    </p>

                    @if($leaseTemplates->isEmpty())
                        <p class="text-sm text-gray-600">You don't have any lease templates yet. <a href="{{ route('lease-templates.index') }}" class="text-orange-500 hover:text-orange-600 transition">Create one first</a>.</p>
                        <div class="flex justify-end gap-3 pt-4">
                            <x-button variant="secondary" type="button" @click="agreementModal = false">Close</x-button>
                        </div>
                    @else
                        <div>
                            <x-label :required="true">Template</x-label>
                            <x-select x-model="agreementTemplateId" required>
                                @foreach($leaseTemplates as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div class="flex justify-end gap-3 pt-4">
                            <x-button variant="secondary" type="button" @click="agreementModal = false">Cancel</x-button>
                            <a :href="'{{ url('leases') }}/' + agreementLease?.id + '/agreement?template_id=' + agreementTemplateId" target="_blank"
                                class="inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-medium bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white transition">
                                Download PDF
                            </a>
                        </div>
                    @endif
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
