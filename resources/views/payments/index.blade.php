<x-app-layout>
    <x-slot name="header">Payments</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        viewModal: false,
        allocateModal: false,
        ownerType: 'tenant',
        viewing: {},
        allocating: {},
        viewAllocationsModal: false,
        viewingAllocations: {},
        viewAllocations(item) {
            this.viewingAllocations = { ...item };
            this.viewAllocationsModal = true;
        },
        view(item) {
            this.viewing = { ...item };
            this.viewModal = true;
        },
        formatLabel(val) {
            if (!val) return '--';
            return val.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        },
        allocate(item) {
            this.allocating = { ...item };
            this.allocateModal = true;
            this.$nextTick(() => {
                let url = '{{ route('search.invoices') }}?unpaid_only=1';
                if (item.payable_type && item.payable_type.includes('Tenant')) {
                    url += '&tenant_id=' + item.payable_id;
                } else if (item.payable_type && item.payable_type.includes('Customer')) {
                    url += '&customer_id=' + item.payable_id;
                }
                this.$dispatch('set-search-url', { name: 'invoice_id', url: url });
            });
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">All Payments</h3>
                    <p class="mt-1 text-sm text-gray-500">Record and manage tenant payments.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('payments.export', request()->query()) }}" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:text-gray-900 hover:border-gray-400 transition">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Export
                    </a>
                    <x-button @click="createModal = true" type="button">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Payment
                    </x-button>
                </div>
            </div>

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('payments.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Payment number, reference, tenant..." />
                    </div>
                    <div class="w-44">
                        <x-label>Tenant</x-label>
                        <x-search-select name="tenant_id" url="{{ route('search.tenants') }}" placeholder="All Tenants" />
                    </div>
                    <div class="w-44">
                        <x-label>Method</x-label>
                        <x-select name="payment_method">
                            <option value="">All Methods</option>
                            <option value="cash" {{ request('payment_method') === 'cash' ? 'selected' : '' }}>Cash</option>
                            <option value="bank_transfer" {{ request('payment_method') === 'bank_transfer' ? 'selected' : '' }}>Bank Transfer</option>
                            <option value="mtn_mobile_money" {{ request('payment_method') === 'mtn_mobile_money' ? 'selected' : '' }}>MTN Mobile Money</option>
                            <option value="airtel_mobile_money" {{ request('payment_method') === 'airtel_mobile_money' ? 'selected' : '' }}>Airtel Mobile Money</option>
                            <option value="flexipay" {{ request('payment_method') === 'flexipay' ? 'selected' : '' }}>Flexipay</option>
                        </x-select>
                    </div>
                    <div class="w-36">
                        <x-label>Status</x-label>
                        <x-select name="status">
                            <option value="">All</option>
                            <option value="unallocated" {{ request('status') === 'unallocated' ? 'selected' : '' }}>Unallocated</option>
                            <option value="partially_allocated" {{ request('status') === 'partially_allocated' ? 'selected' : '' }}>Partially Allocated</option>
                            <option value="fully_allocated" {{ request('status') === 'fully_allocated' ? 'selected' : '' }}>Fully Allocated</option>
                            <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        </x-select>
                    </div>
                    <div class="w-36">
                        <x-label>Date From</x-label>
                        <x-input type="date" name="date_from" value="{{ request('date_from') }}" />
                    </div>
                    <div class="w-36">
                        <x-label>Date To</x-label>
                        <x-input type="date" name="date_to" value="{{ request('date_to') }}" />
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'tenant_id', 'payment_method', 'status', 'date_from', 'date_to']))
                            <a href="{{ route('payments.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                        @endif
                    </div>
                </form>
            </div>

            {{-- Table --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div>
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Number</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Tenant / Customer</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Method</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Unallocated</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($payments as $payment)
                                <tr class="hover:bg-gray-50 transition-colors {{ $payment->status === 'cancelled' ? 'opacity-50' : '' }}">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $payment->number }}</td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2 mb-1">
                                            <div class="text-sm font-medium text-gray-900">{{ $payment->display_payer_name }}</div>
                                            @if($payment->payable_type === \App\Models\Tenant::class)
                                                <x-badge color="gray">Tenant</x-badge>
                                            @elseif($payment->payable_type === \App\Models\Customer::class)
                                                <x-badge color="gray">Customer</x-badge>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900 text-right">UGX {{ number_format($payment->amount, 0) }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $payment->payment_date->format('M d, Y') }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600 capitalize">{{ str_replace('_', ' ', $payment->payment_method) }}</td>
                                    <td class="px-6 py-4">
                                        @if($payment->status === 'cancelled')
                                            <x-badge color="red">Cancelled</x-badge>
                                        @elseif($payment->status === 'unallocated')
                                            <x-badge color="gray" class="!py-0 !px-1.5 text-[10px]">Unallocated</x-badge>
                                        @elseif($payment->status === 'partially_allocated')
                                            <x-badge color="blue" class="!py-0 !px-1.5 text-[10px]">Partial</x-badge>
                                        @elseif($payment->status === 'fully_allocated')
                                            <x-badge color="purple" class="!py-0 !px-1.5 text-[10px]">Fully Allocated</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        @if($payment->status !== 'cancelled')
                                            @php $unallocated = $payment->unallocatedAmount(); @endphp
                                            <span class="text-sm font-medium {{ $unallocated > 0 ? 'text-orange-600' : 'text-green-600' }}">
                                                UGX {{ number_format($unallocated, 0) }}
                                            </span>
                                        @else
                                            <span class="text-sm text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="allocate({{ Js::from($payment->makeVisible('allocations')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-purple-600 hover:bg-gray-50 transition">Allocations</button>
                                                    <button @click="view({{ Js::from($payment->makeHidden('allocations')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                                    @if($payment->status !== 'cancelled')
                                                        @if(!$payment->allocations->count())
                                                            <form action="{{ route('payments.cancel', $payment) }}" method="POST" @submit.prevent="$store.confirm.open('Cancel this payment? This cannot be undone.', $el)">
                                                                @csrf
                                                                @method('PATCH')
                                                                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Cancel</button>
                                                            </form>
                                                        @endif
                                                    @endif
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No payments found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($payments->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $payments->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Payment Details" maxWidth="lg">
                    <div class="space-y-5">
                        <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Number</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.number || '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Status</p>
                                <div class="mt-1 flex gap-2">
                                    <template x-if="viewing.status === 'cancelled'">
                                        <p class="text-sm text-red-600">Cancelled</p>
                                    </template>
                                    <template x-if="viewing.status !== 'cancelled'">
                                        <div class="flex items-center gap-2">
                                            <template x-if="viewing.status === 'unallocated'">
                                                <x-badge color="gray" class="!py-0 !px-1.5 text-[10px]">Unallocated</x-badge>
                                            </template>
                                            <template x-if="viewing.status === 'partially_allocated'">
                                                <x-badge color="blue" class="!py-0 !px-1.5 text-[10px]">Partially Allocated</x-badge>
                                            </template>
                                            <template x-if="viewing.status === 'fully_allocated'">
                                                <x-badge color="purple" class="!py-0 !px-1.5 text-[10px]">Fully Allocated</x-badge>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Payer</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.payable ? viewing.payable.name : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Amount</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.amount ? 'UGX ' + Number(viewing.amount).toLocaleString() : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Payment Date</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.payment_date ? viewing.payment_date.split('T')[0] : '--'"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Payment Method</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="formatLabel(viewing.payment_method)"></p>
                            </div>
                            <div>
                                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Reference</p>
                                <p class="mt-1 text-sm text-gray-900" x-text="viewing.reference || '--'"></p>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Notes</p>
                            <p class="text-sm text-gray-700 whitespace-pre-line" x-text="viewing.notes || 'No notes.'"></p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Receipt Photo</p>
                            <template x-if="viewing.receipt_photo">
                                <a :href="'/storage/' + viewing.receipt_photo" target="_blank">
                                    <img :src="'/storage/' + viewing.receipt_photo" alt="Receipt" class="w-full h-32 object-cover rounded-xl border border-gray-200 hover:border-orange-500 transition" />
                                </a>
                            </template>
                            <template x-if="!viewing.receipt_photo">
                                <div class="w-full h-32 rounded-xl border border-gray-200 bg-gray-50 flex items-center justify-center">
                                    <p class="text-xs text-gray-500">No receipt photo</p>
                                </div>
                            </template>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="viewModal = false">Close</x-button>
                        </div>
                    </div>
                </x-modal>
            </div>
        </template>


        {{-- Allocate Modal --}}
        <template x-if="allocateModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) allocateModal = false })">
                <x-modal title="Allocate Payment" maxWidth="2xl">
                    <div class="space-y-5">
                        {{-- Payment Summary --}}
                        <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <p class="text-xs text-gray-500">Payment</p>
                                    <p class="text-sm font-medium text-gray-900" x-text="allocating.number"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Total Amount</p>
                                    <p class="text-sm font-medium text-gray-900" x-text="'UGX ' + Number(allocating.amount).toLocaleString()"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Unallocated</p>
                                    <p class="text-sm font-medium text-orange-600" x-text="'UGX ' + Number(allocating.amount - (allocating.allocations || []).reduce((sum, a) => sum + Number(a.amount), 0)).toLocaleString()"></p>
                                </div>
                            </div>
                        </div>

                        {{-- Existing Allocations --}}
                        <template x-if="allocating.allocations && allocating.allocations.length > 0">
                            <div>
                                <p class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-2">Existing Allocations</p>
                                <div class="space-y-2">
                                    <template x-for="alloc in allocating.allocations" :key="alloc.id">
                                        <div class="flex items-center justify-between bg-gray-50 rounded-xl px-4 py-3 border border-gray-200">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <p class="text-sm text-gray-900" x-text="alloc.invoice ? (alloc.invoice.number + ' — ' + alloc.invoice.title) : ('Invoice #' + alloc.invoice_id)"></p>
                                                    <template x-if="alloc.invoice && alloc.invoice.invoiceable_type">
                                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider"
                                                              :class="alloc.invoice.invoiceable_type.includes('Tenant') ? 'bg-blue-500/10 text-blue-600 border border-blue-500/20' : 'bg-purple-500/10 text-purple-600 border border-purple-500/20'"
                                                              x-text="alloc.invoice.invoiceable_type.includes('Tenant') ? 'Tenant' : 'Customer'"></span>
                                                    </template>
                                                </div>
                                                <p class="text-xs text-gray-500 mt-0.5" x-text="'UGX ' + Number(alloc.amount).toLocaleString()"></p>
                                            </div>

                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- Add New Allocation --}}
                        <template x-if="Number(allocating.amount - (allocating.allocations || []).reduce((sum, a) => sum + Number(a.amount), 0)) > 0">
                            <div class="border-t border-gray-200 pt-4">
                                <p class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3">Add Allocation</p>
                                <form action="{{ route('payment-allocations.store') }}" method="POST" class="space-y-4" @submit.prevent="$store.confirm.open('Are you sure you want to allocate this payment? This action is irreversible.', $el)">
                                    @csrf
                                    <input type="hidden" name="payment_id" :value="allocating.id">
                                    <div>
                                        <x-label :required="true">Invoice</x-label>
                                        <x-search-select name="invoice_id" url="" placeholder="Search tenant invoices..." required />
                                    </div>
                                    <div>
                                        <x-label :required="true">Amount</x-label>
                                        <x-money-input name="amount" placeholder="0" required />
                                    </div>
                                    <div class="flex justify-end gap-3">
                                        <x-button variant="secondary" type="button" @click="allocateModal = false">Close</x-button>
                                        <x-button type="submit">Allocate</x-button>
                                    </div>
                                </form>
                            </div>
                        </template>
                        <template x-if="Number(allocating.amount - (allocating.allocations || []).reduce((sum, a) => sum + Number(a.amount), 0)) === 0">
                            <div class="flex justify-end pt-4 border-t border-gray-200">
                                <x-button variant="secondary" type="button" @click="allocateModal = false">Close</x-button>
                            </div>
                        </template>
                    </div>
                </x-modal>
            </div>
        </template>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Payment" maxWidth="lg">
                    <form action="{{ route('payments.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
                        @csrf
                        <div class="flex gap-4 mb-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" x-model="ownerType" value="tenant" class="text-orange-500 bg-gray-50 border-gray-200 focus:ring-orange-500 focus:ring-offset-white duration-200">
                                <span class="text-sm text-gray-700">Tenant</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" x-model="ownerType" value="customer" class="text-orange-500 bg-gray-50 border-gray-200 focus:ring-orange-500 focus:ring-offset-white duration-200">
                                <span class="text-sm text-gray-700">Casual Customer</span>
                            </label>
                        </div>
                        <div x-show="ownerType === 'tenant'">
                            <x-label :required="true">Tenant</x-label>
                            <x-search-select name="tenant_id" url="{{ route('search.tenants') }}" placeholder="Search tenants..." x-bind:required="ownerType === 'tenant'" />
                        </div>
                        <div x-show="ownerType === 'customer'">
                            <x-label :required="true">Customer</x-label>
                            <x-search-select name="customer_id" url="{{ route('search.customers') }}" placeholder="Search customers..." x-bind:required="ownerType === 'customer'" />
                        </div>
                        <div>
                            <x-label :required="true">Amount</x-label>
                            <x-money-input name="amount" placeholder="0.00" required />
                        </div>
                        <div>
                            <x-label :required="true">Payment Date</x-label>
                            <x-input type="date" name="payment_date" required />
                        </div>
                        <div>
                            <x-label :required="true">Payment Method</x-label>
                            <x-select name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mtn_mobile_money">MTN Mobile Money</option>
                                <option value="airtel_mobile_money">Airtel Mobile Money</option>
                                <option value="flexipay">Flexipay</option>
                            </x-select>
                        </div>
                        <div>
                            <x-label>Reference</x-label>
                            <x-input name="reference" placeholder="Transaction reference..." />
                        </div>
                        <div>
                            <x-label>Receipt Photo</x-label>
                            <input type="file" name="receipt_photo" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" placeholder="Optional notes..."></x-textarea>
                        </div>
                        <div class="flex items-center gap-2 pt-2">
                            <input type="checkbox" id="send_sms" name="send_sms" value="1" class="w-4 h-4 text-orange-500 bg-gray-50 border-gray-200 rounded focus:ring-orange-500 focus:ring-offset-white transition duration-200 cursor-pointer">
                            <label for="send_sms" class="text-sm text-gray-700 font-medium cursor-pointer select-none">Send SMS Notification to Payer</label>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Payment</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
