<x-app-layout>
    <x-slot name="header">Expenses</x-slot>

    <x-tip id="expenses" title="Track what each property costs">
        Log expenses against an estate or unit to see what each one really earns. Repairs completed on a job card can record their expense here for you.
    </x-tip>

    <div class="py-8" x-data="{
        createModal: {{ request()->has('new') ? 'true' : 'false' }},
        editModal: false,
        viewModal: false,
        viewing: {},
        editing: {
            id: null,
            number: '',
            date: '',
            expense_item_id: '',
            estate_id: '',
            unit_id: '',
            amount: '',
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
                this.$dispatch('set-search-select', { name: 'expense_item_id', id: item.expense_item_id, text: item.expense_item?.name || '' });
                this.$dispatch('set-search-select', { name: 'estate_id', id: item.estate_id, text: item.estate?.name || '' });
                this.$dispatch('set-search-select', { name: 'unit_id', id: item.unit_id, text: item.unit?.name || '' });
                this.$dispatch('set-money-input', { name: 'amount', value: item.amount });
            });
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">All Expenses</h3>
                    <p class="mt-1 text-sm text-gray-500">Track and manage property-related expenses.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a hx-boost="false" href="{{ route('expenses.export', request()->query()) }}" class="inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:text-gray-900 hover:border-gray-400 transition">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Export
                    </a>
                    <x-button @click="createModal = true" type="button">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Expense
                    </x-button>
                </div>
            </div>

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('expenses.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Expense number, notes, item..." />
                    </div>
                    <div class="w-44">
                        <x-label>Estate</x-label>
                        <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="All Estates" />
                    </div>
                    <div class="w-44">
                        <x-label>Expense Item</x-label>
                        <x-search-select name="expense_item_id" url="{{ route('search.expense-items') }}" placeholder="All Items" />
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
                        @if(request()->hasAny(['search', 'estate_id', 'expense_item_id', 'date_from', 'date_to']))
                            <a href="{{ route('expenses.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
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
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Item</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Estate</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Unit</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($expenses as $expense)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $expense->number }}@if($expense->vendorJob)<span class="block text-xs font-normal text-gray-500">Job {{ $expense->vendorJob->number }}</span>@endif</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $expense->date->format('M d, Y') }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $expense->expenseItem?->name ?? '--' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $expense->estate?->name ?? '--' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $expense->unit?->name ?? '--' }}</td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900 text-right">UGX {{ number_format($expense->amount, 2) }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({{ Js::from($expense) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                                    <button @click="edit({{ Js::from($expense) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    <form action="{{ route('expenses.destroy', $expense) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this expense?', $el)">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                                    </form>
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No expenses found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($expenses as $expense)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 break-words">{{ $expense->number }}@if($expense->vendorJob) <span class="text-xs font-normal text-gray-500">· Job {{ $expense->vendorJob->number }}</span>@endif</p>
                                    <p class="mt-0.5 text-sm text-gray-600">{{ $expense->date->format('M d, Y') }}</p>
                                    <p class="text-sm text-gray-600">{{ $expense->expenseItem?->name ?? '--' }}</p>
                                </div>
                                <x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        <button @click="view({{ Js::from($expense) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                        <button @click="edit({{ Js::from($expense) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                        <form action="{{ route('expenses.destroy', $expense) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this expense?', $el)">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                        </form>
                                    </x-slot>
                                </x-dropdown>
                            </div>
                            <div class="mt-3 space-y-1">
                                <p class="text-sm text-gray-600"><span class="text-gray-500">Estate:</span> {{ $expense->estate?->name ?? '--' }}</p>
                                <p class="text-sm text-gray-600"><span class="text-gray-500">Unit:</span> {{ $expense->unit?->name ?? '--' }}</p>
                                <p class="text-sm font-medium text-gray-900"><span class="text-gray-500 font-normal">Amount:</span> UGX {{ number_format($expense->amount, 2) }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No expenses found.</p>
                        </div>
                    @endforelse
                </div>

                @if ($expenses->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $expenses->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Expense Details" maxWidth="lg">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500">Number</p>
                            <p class="text-sm text-gray-900" x-text="viewing.number || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Date</p>
                            <p class="text-sm text-gray-900" x-text="viewing.date ? viewing.date.split('T')[0] : '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Expense Item</p>
                            <p class="text-sm text-gray-900" x-text="viewing.expense_item?.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Estate</p>
                            <p class="text-sm text-gray-900" x-text="viewing.estate?.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Unit</p>
                            <p class="text-sm text-gray-900" x-text="viewing.unit?.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Amount</p>
                            <p class="text-sm text-gray-900" x-text="viewing.amount ? 'UGX ' + Number(viewing.amount).toLocaleString() : '--'"></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-xs text-gray-500">Notes</p>
                            <p class="text-sm text-gray-700 whitespace-pre-line" x-text="viewing.notes || 'No notes.'"></p>
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
                <x-modal title="Add Expense" maxWidth="lg">
                    <form action="{{ route('expenses.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Date</x-label>
                            <x-input type="date" name="date" required />
                        </div>
                        <div>
                            <x-label :required="true">Expense Item</x-label>
                            <x-search-select name="expense_item_id" url="{{ route('search.expense-items') }}" placeholder="Search items..." required />
                        </div>
                        <div>
                            <x-label :required="true">Estate</x-label>
                            <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="Search estates..." required />
                        </div>
                        <div>
                            <x-label>Unit (Optional)</x-label>
                            <x-search-select name="unit_id" url="{{ route('search.units') }}" placeholder="Search units..." />
                        </div>
                        <div>
                            <x-label :required="true">Amount</x-label>
                            <x-money-input name="amount" placeholder="0.00" required />
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" placeholder="Optional notes..."></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Expense</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Expense" maxWidth="lg">
                    <form :action="'{{ url('expenses') }}/' + editing.id" method="POST" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label>Number</x-label>
                            <x-input name="number" x-model="editing.number" readonly class="opacity-50 cursor-not-allowed" />
                        </div>
                        <div>
                            <x-label :required="true">Date</x-label>
                            <x-input type="date" name="date" x-bind:value="editing.date ? editing.date.split('T')[0] : ''" required />
                        </div>
                        <div>
                            <x-label :required="true">Expense Item</x-label>
                            <x-search-select name="expense_item_id" url="{{ route('search.expense-items') }}" placeholder="Search items..." required />
                        </div>
                        <div>
                            <x-label :required="true">Estate</x-label>
                            <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="Search estates..." required />
                        </div>
                        <div>
                            <x-label>Unit (Optional)</x-label>
                            <x-search-select name="unit_id" url="{{ route('search.units') }}" placeholder="Search units..." />
                        </div>
                        <div>
                            <x-label :required="true">Amount</x-label>
                            <x-money-input name="amount" placeholder="0.00" required />
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" x-model="editing.notes"></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Expense</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
