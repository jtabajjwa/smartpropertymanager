<x-app-layout>
    <x-slot name="header">Invoices</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        viewModal: false,
        viewing: {},
        ownerType: 'lease',
        createType: 'rent',
        editing: {
            id: null,
            number: '',
            lease_id: '',
            type: '',
            utility_id: '',
            title: '',
            invoice_date: '',
            amount: '',
            due_date: '',
            status: '',
            notes: ''
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
            this.$nextTick(() => {
                this.$dispatch('set-search-select', { name: 'lease_id', id: item.lease_id, text: item.lease ? (item.lease.number + ' — ' + (item.lease.tenant?.name || '')) : '' });
                this.$dispatch('set-search-select', { name: 'utility_id', id: item.utility_id, text: item.utility?.name || '' });
                this.$dispatch('set-money-input', { name: 'amount', value: item.amount });
            });
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-white">All Invoices</h3>
                    <p class="mt-1 text-sm text-gray-500">Create and manage tenant invoices.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('invoices.export', request()->query()) }}" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-gray-700 text-gray-300 hover:text-white hover:border-gray-500 transition">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Export
                    </a>
                    <x-button @click="createModal = true; createType = 'rent'" type="button">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Invoice
                    </x-button>
                </div>
            </div>

            {{-- Filters --}}
            <div class="bg-gray-900 rounded-2xl border border-white/10 p-4 mb-6">
                <form method="GET" action="{{ route('invoices.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Invoice number, title, tenant..." />
                    </div>
                    <div class="w-44">
                        <x-label>Tenant</x-label>
                        <x-search-select name="tenant_id" url="{{ route('search.tenants') }}" placeholder="All Tenants" />
                    </div>
                    <div class="w-44">
                        <x-label>Customer</x-label>
                        <x-search-select name="customer_id" url="{{ route('search.customers') }}" placeholder="All Customers" />
                    </div>
                    <div class="w-44">
                        <x-label>Estate</x-label>
                        <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="All Estates" />
                    </div>
                    <div class="w-44">
                        <x-label>Lease</x-label>
                        <x-search-select name="lease_id" url="{{ route('search.leases') }}" placeholder="All Leases" />
                    </div>
                    <div class="w-36">
                        <x-label>Status</x-label>
                        <x-select name="status">
                            <option value="">All Statuses</option>
                            <option value="unpaid" {{ request('status') === 'unpaid' ? 'selected' : '' }}>Unpaid</option>
                            <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>Paid</option>
                            <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                            <option value="written_off" {{ request('status') === 'written_off' ? 'selected' : '' }}>Written Off</option>
                        </x-select>
                    </div>
                    <div class="w-36">
                        <x-label>Due From</x-label>
                        <x-input type="date" name="due_from" value="{{ request('due_from') }}" />
                    </div>
                    <div class="w-36">
                        <x-label>Due To</x-label>
                        <x-input type="date" name="due_to" value="{{ request('due_to') }}" />
                    </div>
                    <div class="w-40">
                        <x-label>Type</x-label>
                        <x-select name="type">
                            <option value="">All Types</option>
                            <option value="rent" {{ request('type') === 'rent' ? 'selected' : '' }}>Rent</option>
                            <option value="security_deposit" {{ request('type') === 'security_deposit' ? 'selected' : '' }}>Security Deposit</option>
                            <option value="utility" {{ request('type') === 'utility' ? 'selected' : '' }}>Utility</option>
                        </x-select>
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'tenant_id', 'customer_id', 'estate_id', 'lease_id', 'status', 'type', 'due_from', 'due_to']))
                            <a href="{{ route('invoices.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-400 hover:text-white transition">Clear</a>
                        @endif
                    </div>
                </form>
            </div>

            {{-- Table --}}
            <div class="bg-gray-900 rounded-2xl shadow-sm border border-white/10">
                <div>
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Number</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Tenant / Customer</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Title</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider">Balance</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Due Date</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @forelse ($invoices as $invoice)
                                <tr class="hover:bg-white/5 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-white">{{ $invoice->number }}</td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2 mb-1">
                                            <div class="text-sm font-medium text-white">{{ $invoice->display_payer_name }}</div>
                                        </div>
                                        @if($invoice->invoiceable_type === \App\Models\Lease::class && $invoice->invoiceable)
                                            <div class="text-xs text-gray-400">Lease #{{ $invoice->invoiceable->number }}</div>
                                        @endif
                                        <div>
                                            @if($invoice->invoiceable_type === \App\Models\Lease::class)
                                                <x-badge color="gray">Tenant</x-badge>
                                            @elseif($invoice->invoiceable_type === \App\Models\Customer::class)
                                                <x-badge color="gray">Customer</x-badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-400">{{ $invoice->title }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-400 capitalize">
                                        {{ str_replace('_', ' ', $invoice->type) }}
                                        @if($invoice->type === 'utility' && $invoice->utility)
                                            <span class="text-xs text-gray-400">({{ $invoice->utility->name }})</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium text-white text-right">UGX {{ number_format($invoice->amount, 0) }}</td>
                                    @php $balance = $invoice->unpaidBalance(); @endphp
                                    <td class="px-6 py-4 text-sm font-medium text-right {{ $balance > 0 ? 'text-orange-400' : 'text-green-400' }}">UGX {{ number_format($balance, 0) }}</td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-400">{{ $invoice->due_date->format('M d, Y') }}</div>
                                        @if(!in_array($invoice->status, ['paid', 'cancelled']) && $invoice->due_date->isPast())
                                            <div class="text-xs text-red-500 font-medium mt-0.5">Overdue</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        @if($invoice->status === 'paid')
                                            <x-badge color="green">Paid</x-badge>
                                        @elseif($invoice->status === 'partial')
                                            <x-badge color="orange">Partial</x-badge>
                                        @elseif($invoice->status === 'cancelled')
                                            <x-badge color="gray">Cancelled</x-badge>
                                        @elseif($invoice->status === 'written_off')
                                            <x-badge color="gray">Written&nbsp;Off</x-badge>
                                        @else
                                            <x-badge color="red">Unpaid</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({ ...{{ Js::from($invoice->makeHidden('allocations')) }}, balance: {{ $balance }}, editable: {{ $invoice->status !== 'cancelled' && !$invoice->allocations->count() ? 'true' : 'false' }} })" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-400 hover:bg-white/5 transition">View</button>
                                                    @if(in_array($invoice->status, ['unpaid', 'partial']) && $invoice->invoiceable_type === \App\Models\Lease::class && in_array($invoice->invoiceable?->status, ['terminated', 'evicted']))
                                                        <form action="{{ route('invoices.write-off', $invoice) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to write off this invoice? This debt will be marked as uncollectible.', $el)">
                                                            @csrf
                                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-gray-400 hover:bg-white/5 transition">Write Off</button>
                                                        </form>
                                                    @endif
                                                    @if(!in_array($invoice->status, ['cancelled', 'written_off']) && !$invoice->allocations->count())
                                                        <button @click="edit({{ Js::from($invoice->makeHidden('allocations')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-400 hover:bg-white/5 transition">Edit</button>
                                                        <form action="{{ route('invoices.cancel', $invoice) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to cancel this invoice? This cannot be undone.', $el)">
                                                            @csrf
                                                            @method('PATCH')
                                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-white/5 transition">Cancel</button>
                                                        </form>
                                                    @endif
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-400">No invoices found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($invoices->hasPages())
                    <div class="px-6 py-4 border-t border-white/10">
                        {{ $invoices->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Invoice Details" maxWidth="lg">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500">Number</p>
                            <p class="text-sm text-white" x-text="viewing.number || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Type</p>
                            <p class="text-sm text-white" x-text="formatLabel(viewing.type)"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Title</p>
                            <p class="text-sm text-white" x-text="viewing.title || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Payer</p>
                            <p class="text-sm text-white" x-text="viewing.invoiceable ? (viewing.invoiceable_type.includes('Lease') ? (viewing.invoiceable.number + ' — ' + (viewing.invoiceable.tenant?.name || '')) : viewing.invoiceable.name ) : '--'"></p>
                        </div>
                        <div x-show="viewing.type === 'utility'">
                            <p class="text-xs text-gray-500">Utility</p>
                            <p class="text-sm text-white" x-text="viewing.utility?.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Invoice Date</p>
                            <p class="text-sm text-white" x-text="viewing.invoice_date ? viewing.invoice_date.split('T')[0] : '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Due Date</p>
                            <p class="text-sm text-white" x-text="viewing.due_date ? viewing.due_date.split('T')[0] : '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Amount</p>
                            <p class="text-sm text-white" x-text="viewing.amount ? 'UGX ' + Number(viewing.amount).toLocaleString() : '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Balance</p>
                            <p class="text-sm font-medium" :class="viewing.balance > 0 ? 'text-orange-400' : 'text-green-400'" x-text="viewing.balance !== undefined ? 'UGX ' + Number(viewing.balance).toLocaleString() : '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Status</p>
                            <p class="text-sm text-white" x-text="formatLabel(viewing.status)"></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-xs text-gray-500">Notes</p>
                            <p class="text-sm text-gray-300 whitespace-pre-line" x-text="viewing.notes || 'No notes.'"></p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 pt-4">
                        <x-button variant="secondary" type="button" @click="viewModal = false">Close</x-button>
                        <template x-if="viewing.editable">
                            <x-button type="button" @click="viewModal = false; edit(viewing)">Edit</x-button>
                        </template>
                    </div>
                </x-modal>
            </div>
        </template>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Invoice" maxWidth="lg">
                    <form action="{{ route('invoices.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div class="flex gap-4 mb-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" x-model="ownerType" value="lease" class="text-orange-500 bg-white/5 border-white/10 focus:ring-orange-500 focus:ring-offset-gray-900 duration-200">
                                <span class="text-sm text-gray-300">Tenant (Lease)</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" x-model="ownerType" value="customer" class="text-orange-500 bg-white/5 border-white/10 focus:ring-orange-500 focus:ring-offset-gray-900 duration-200">
                                <span class="text-sm text-gray-300">Casual Customer</span>
                            </label>
                        </div>
                        <div x-show="ownerType === 'lease'">
                            <x-label :required="true">Lease</x-label>
                            <x-search-select name="lease_id" url="{{ route('search.leases') }}" placeholder="Search leases..." x-bind:required="ownerType === 'lease'" />
                        </div>
                        <div x-show="ownerType === 'customer'">
                            <x-label :required="true">Customer</x-label>
                            <x-search-select name="customer_id" url="{{ route('search.customers') }}" placeholder="Search customers..." x-bind:required="ownerType === 'customer'" />
                        </div>
                        <div>
                            <x-label :required="true">Type</x-label>
                            <x-select name="type" x-model="createType" required x-effect="if (ownerType === 'customer') createType = 'utility'">
                                <template x-if="ownerType === 'lease'">
                                    <option value="rent">Rent</option>
                                </template>
                                <template x-if="ownerType === 'lease'">
                                    <option value="security_deposit">Security Deposit</option>
                                </template>
                                <option value="utility">Utility</option>
                            </x-select>
                        </div>
                        <div x-show="createType === 'utility'">
                            <x-label>Utility</x-label>
                            <x-search-select name="utility_id" url="{{ route('search.utilities') }}" placeholder="Search utilities..." />
                        </div>
                        <div>
                            <x-label :required="true">Title</x-label>
                            <x-input name="title" placeholder="e.g. January 2026 Rent" required />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Invoice Date</x-label>
                                <x-input type="date" name="invoice_date" required />
                            </div>
                            <div>
                                <x-label :required="true">Due Date</x-label>
                                <x-input type="date" name="due_date" required />
                            </div>
                        </div>
                        <div>
                            <x-label :required="true">Amount</x-label>
                            <x-money-input name="amount" placeholder="0.00" required />
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" placeholder="Optional notes..."></x-textarea>
                        </div>
                        <div class="flex items-center gap-2 pt-2">
                            <input type="checkbox" id="send_sms" name="send_sms" value="1" class="w-4 h-4 text-orange-500 bg-white/5 border-white/10 rounded focus:ring-orange-500 focus:ring-offset-gray-900 transition duration-200 cursor-pointer">
                            <label for="send_sms" class="text-sm text-gray-300 font-medium cursor-pointer select-none">Send SMS Notification to Payer</label>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Invoice</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Invoice" maxWidth="lg">
                    <form :action="'{{ url('invoices') }}/' + editing.id" method="POST" class="space-y-5">
                        @csrf
                        @method('PUT')

                        {{-- Read-only summary --}}
                        <div class="grid grid-cols-2 gap-x-6 gap-y-3 p-4 bg-white/5 rounded-xl border border-white/10">
                            <div>
                                <p class="text-xs text-gray-500">Number</p>
                                <p class="text-sm text-white font-medium" x-text="editing.number"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Type</p>
                                <p class="text-sm text-white" x-text="formatLabel(editing.type)"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Payer</p>
                                <p class="text-sm text-white" x-text="editing.invoiceable ? (editing.invoiceable_type.includes('Lease') ? (editing.invoiceable.number + ' — ' + (editing.invoiceable.tenant?.name || '')) : editing.invoiceable.name ) : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Invoice Date</p>
                                <p class="text-sm text-white" x-text="editing.invoice_date ? editing.invoice_date.split('T')[0] : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Title</p>
                                <p class="text-sm text-white" x-text="editing.title || '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Amount</p>
                                <p class="text-sm text-white" x-text="editing.amount ? 'UGX ' + Number(editing.amount).toLocaleString() : '--'"></p>
                            </div>
                        </div>

                        {{-- Editable fields --}}
                        <div>
                            <x-label :required="true">Due Date</x-label>
                            <x-input type="date" name="due_date" x-bind:value="editing.due_date ? editing.due_date.split('T')[0] : ''" required />
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" x-model="editing.notes"></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Invoice</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
