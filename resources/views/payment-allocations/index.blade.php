<x-app-layout>
    <x-slot name="header">Payment Allocations</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        viewModal: false,
        viewing: {},
        editing: {
            id: null,
            payment_id: '',
            invoice_id: '',
            amount: ''
        },
        view(item) {
            this.viewing = { ...item };
            this.viewModal = true;
        },
        edit(item) {
            this.editing = { ...item };
            this.editModal = true;
            this.$nextTick(() => {
                this.$dispatch('set-search-select', { name: 'payment_id', id: item.payment_id, text: item.payment ? (item.payment.number + ' — ' + (item.payment.display_payer_name || '')) : '' });
                this.$dispatch('set-search-select', { name: 'invoice_id', id: item.invoice_id, text: item.invoice ? (item.invoice.number + ' — ' + (item.invoice.display_payer_name || '')) : '' });
                this.$dispatch('set-money-input', { name: 'amount', value: item.amount });
            });
        },
        formatLabel(val) {
            if (!val) return '--';
            return val.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-white">All Allocations</h3>
                    <p class="mt-1 text-sm text-gray-500">Allocate payments to specific invoices.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Allocation
                </x-button>
            </div>

            {{-- Table --}}
            <div class="bg-gray-900 rounded-2xl shadow-sm border border-white/10">
                <div>
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Payment</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Invoice</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @forelse ($allocations as $allocation)
                                <tr class="hover:bg-white/5 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-white">{{ $allocation->payment?->number ?? '--' }} <span class="text-xs text-gray-500 block font-normal">{{ $allocation->payment?->display_payer_name }}</span></td>
                                    <td class="px-6 py-4 text-sm text-gray-400">{{ $allocation->invoice?->number ?? '--' }} <span class="text-xs text-gray-500 block font-normal">{{ $allocation->invoice?->display_payer_name }}</span></td>
                                    <td class="px-6 py-4 text-sm font-medium text-white text-right">UGX {{ number_format($allocation->amount, 2) }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({{ Js::from($allocation) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-400 hover:bg-white/5 transition">View</button>
                                                    <button @click="edit({{ Js::from($allocation) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-400 hover:bg-white/5 transition">Edit</button>
                                                    <form action="{{ route('payment-allocations.destroy', $allocation) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this allocation?', $el)">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-white/5 transition">Delete</button>
                                                    </form>
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-400">No allocations found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($allocations->hasPages())
                    <div class="px-6 py-4 border-t border-white/10">
                        {{ $allocations->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Allocation Details" maxWidth="lg">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Payment</p>
                            <p class="text-sm text-white" x-text="viewing.payment ? (viewing.payment.number + ' — ' + (viewing.payment.display_payer_name || '')) : '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Invoice</p>
                            <p class="text-sm text-white" x-text="viewing.invoice ? (viewing.invoice.number + ' — ' + (viewing.invoice.display_payer_name || '')) : '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Amount</p>
                            <p class="text-sm text-white" x-text="viewing.amount ? 'UGX ' + Number(viewing.amount).toLocaleString() : '--'"></p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 pt-4">
                        <x-button variant="secondary" type="button" @click="viewModal = false">Close</x-button>
                        <x-button type="button" @click="viewModal = false; edit(viewing)">Edit</x-button>
                    </div>
                </x-modal>
            </div>
        </template>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Allocation" maxWidth="lg">
                    <form action="{{ route('payment-allocations.store') }}" method="POST" class="space-y-5" @submit.prevent="$store.confirm.open('Are you sure you want to allocate this payment? This action is irreversible.', $el)">
                        @csrf
                        <div>
                            <x-label :required="true">Payment</x-label>
                            <x-search-select name="payment_id" url="{{ route('search.payments') }}" placeholder="Search payments..." required />
                        </div>
                        <div>
                            <x-label :required="true">Invoice</x-label>
                            <x-search-select name="invoice_id" url="{{ route('search.invoices') }}" placeholder="Search invoices..." required />
                        </div>
                        <div>
                            <x-label :required="true">Amount</x-label>
                            <x-money-input name="amount" placeholder="0.00" required />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Allocation</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Allocation" maxWidth="lg">
                    <form :action="'{{ url('payment-allocations') }}/' + editing.id" method="POST" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label :required="true">Payment</x-label>
                            <x-search-select name="payment_id" url="{{ route('search.payments') }}" placeholder="Search payments..." required />
                        </div>
                        <div>
                            <x-label :required="true">Invoice</x-label>
                            <x-search-select name="invoice_id" url="{{ route('search.invoices') }}" placeholder="Search invoices..." required />
                        </div>
                        <div>
                            <x-label :required="true">Amount</x-label>
                            <x-money-input name="amount" placeholder="0.00" required />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Allocation</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
