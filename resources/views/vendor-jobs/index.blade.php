<x-app-layout>
    <x-slot name="header">Job Cards</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        viewModal: false,
        viewing: {},
        editing: {
            id: null,
            vendor_id: '',
            estate_id: '',
            unit_id: '',
            expense_id: '',
            title: '',
            description: '',
            job_date: '',
            status: 'scheduled',
            cost: ''
        },
        view(item) {
            this.viewing = { ...item };
            this.viewModal = true;
        },
        edit(item) {
            this.editing = { ...item };
            this.editModal = true;
        },
        // Open the edit form already set to Completed, to record the job's cost.
        complete(item) {
            this.edit({ ...item, _complete: true });
        },
        // State for the Record the cost section (vendor-jobs/_costing).
        costingFor(item) {
            const fallback = @js($defaultItem ? ['id' => $defaultItem->id, 'name' => $defaultItem->name] : ['id' => '', 'name' => '']);
            return {
                status: item._complete ? 'completed' : (item.status || 'scheduled'),
                record: item.no_charge ? 'none' : 'expense',
                wasCompleted: item.status === 'completed',
                expense: item.expense || null,
                expense_item_id: item.expense?.expense_item_id || fallback.id,
                expense_item_name: item.expense?.expense_item?.name || fallback.name,
                paid_on: (item.expense?.date || @js(today()->toDateString())).split('T')[0],
            };
        },
        formatLabel(val) {
            if (!val) return '--';
            return val.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        },
        statusColor(status) {
            return { scheduled: 'gray', in_progress: 'blue', completed: 'green', cancelled: 'red' }[status] || 'gray';
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Job Cards</h3>
                    <p class="mt-1 text-sm text-gray-500">Track work performed by vendors. Completing a job records what it cost as an expense.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Job Card
                </x-button>
            </div>

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('vendor-jobs.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Job #, title, vendor..." />
                    </div>
                    <div class="w-48">
                        <x-label>Vendor</x-label>
                        <x-search-select name="vendor_id" url="{{ route('search.vendors') }}" placeholder="All Vendors" value="{{ request('vendor_id') }}" />
                    </div>
                    <div class="w-40">
                        <x-label>Status</x-label>
                        <x-select name="status">
                            <option value="">All Statuses</option>
                            @foreach(\App\Models\VendorJob::STATUSES as $value => $label)
                                <option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'vendor_id', 'status', 'costing']))
                            <a href="{{ route('vendor-jobs.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                        @endif
                    </div>
                </form>
            </div>

            @if($unrecorded > 0 && request('costing') !== 'unrecorded')
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-orange-200 bg-orange-50 px-4 py-3">
                    <p class="text-sm text-gray-700">{{ $unrecorded }} completed {{ Str::plural('job', $unrecorded) }} {{ $unrecorded === 1 ? 'has' : 'have' }} no expense recorded, so {{ $unrecorded === 1 ? 'its' : 'their' }} cost isn't in your books.</p>
                    <a href="{{ route('vendor-jobs.index', ['costing' => 'unrecorded']) }}" class="text-sm font-medium text-orange-700 hover:text-orange-800">Show {{ $unrecorded === 1 ? 'it' : 'them' }} &rarr;</a>
                </div>
            @elseif(request('costing') === 'unrecorded')
                <p class="mb-4 text-sm text-gray-600">Completed jobs with no expense recorded. Use <span class="font-medium">Record expense</span> on each.</p>
            @endif

            {{-- Table (md and up) --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Job #</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Vendor</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Title / Property</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Cost</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($vendorJobs as $job)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $job->number }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $job->vendor->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        {{ $job->title }}
                                        @if($job->estate || $job->unit)
                                            <span class="text-xs text-gray-500 block font-normal">{{ $job->estate?->name }}{{ $job->unit ? ' — ' . $job->unit->name : '' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $job->job_date->format('M d, Y') }}</td>
                                    <td class="px-6 py-4">
                                        <x-badge :color="['scheduled' => 'gray', 'in_progress' => 'blue', 'completed' => 'green', 'cancelled' => 'red'][$job->status] ?? 'gray'">{{ $job->status_label }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900 text-right">
                                        {{ $job->cost !== null ? 'UGX ' . number_format($job->cost, 2) : '--' }}
                                        @if($job->expense)
                                            <span class="text-xs text-green-600 block font-normal">Expense {{ $job->expense->number }}</span>
                                        @elseif($job->no_charge)
                                            <span class="text-xs text-gray-500 block font-normal">No charge</span>
                                        @elseif($job->needsExpense())
                                            <span class="text-xs text-orange-600 block font-normal">No expense recorded</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({{ Js::from($job) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                                    <button @click="edit({{ Js::from($job) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    @if($job->needsExpense() || in_array($job->status, ['scheduled', 'in_progress'], true))
                                                        <button @click="complete({{ Js::from($job) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-green-700 hover:bg-gray-50 transition">{{ $job->needsExpense() ? 'Record expense' : 'Mark completed' }}</button>
                                                    @endif
                                                    <form action="{{ route('vendor-jobs.destroy', $job) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this job card?', $el)">
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
                                        <p class="text-sm text-gray-600">No job cards found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($vendorJobs as $job)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 break-words">{{ $job->number }} — {{ $job->title }}</p>
                                    <p class="mt-0.5 text-sm text-gray-600 break-words">{{ $job->vendor->name }}</p>
                                    @if($job->estate || $job->unit)
                                        <p class="text-sm text-gray-500 break-words">{{ $job->estate?->name }}{{ $job->unit ? ' — ' . $job->unit->name : '' }}</p>
                                    @endif
                                </div>
                                <x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        <button @click="view({{ Js::from($job) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                        <button @click="edit({{ Js::from($job) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    @if($job->needsExpense() || in_array($job->status, ['scheduled', 'in_progress'], true))
                                                        <button @click="complete({{ Js::from($job) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-green-700 hover:bg-gray-50 transition">{{ $job->needsExpense() ? 'Record expense' : 'Mark completed' }}</button>
                                                    @endif
                                        <form action="{{ route('vendor-jobs.destroy', $job) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this job card?', $el)">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                        </form>
                                    </x-slot>
                                </x-dropdown>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-badge :color="['scheduled' => 'gray', 'in_progress' => 'blue', 'completed' => 'green', 'cancelled' => 'red'][$job->status] ?? 'gray'">{{ $job->status_label }}</x-badge>
                            </div>
                            <div class="mt-3 space-y-1">
                                <p class="text-sm text-gray-600"><span class="text-gray-500">Date:</span> {{ $job->job_date->format('M d, Y') }}</p>
                                <p class="text-sm text-gray-600"><span class="text-gray-500">Cost:</span> {{ $job->cost !== null ? 'UGX ' . number_format($job->cost, 2) : '--' }}</p>
                                @if($job->expense)
                                    <p class="text-sm text-green-600">Expense {{ $job->expense->number }}</p>
                                @elseif($job->no_charge)
                                    <p class="text-sm text-gray-500">No charge</p>
                                @elseif($job->needsExpense())
                                    <p class="text-sm text-orange-600">No expense recorded</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No job cards found.</p>
                        </div>
                    @endforelse
                </div>

                @if ($vendorJobs->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $vendorJobs->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Job Card Details" maxWidth="lg">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Job #</p>
                            <p class="text-sm text-gray-900 font-medium" x-text="viewing.number || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Status</p>
                            <p class="text-sm text-gray-900" x-text="formatLabel(viewing.status)"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Vendor</p>
                            <p class="text-sm text-gray-900" x-text="viewing.vendor ? viewing.vendor.name : '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Vendor Phone</p>
                            <p class="text-sm text-gray-900" x-text="viewing.vendor?.phone || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Property</p>
                            <p class="text-sm text-gray-900" x-text="(viewing.estate?.name || '--') + (viewing.unit ? ' — ' + viewing.unit.name : '')"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Job Date</p>
                            <p class="text-sm text-gray-900" x-text="viewing.job_date || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Cost</p>
                            <p class="text-sm text-gray-900" x-text="viewing.cost ? 'UGX ' + Number(viewing.cost).toLocaleString() : '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Expense</p>
                            <p class="text-sm text-gray-900" x-text="viewing.expense ? (viewing.expense.number + ' — UGX ' + Number(viewing.expense.amount).toLocaleString()) : (viewing.no_charge ? 'No charge' : (viewing.status === 'completed' ? 'No expense recorded' : 'Recorded when completed'))"></p>
                        </div>
                        <div class="col-span-1 sm:col-span-2">
                            <p class="text-xs text-gray-500 mb-1">Description / Work Done</p>
                            <p x-text="viewing.description || 'No description.'" class="text-sm text-gray-700 whitespace-pre-line"></p>
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
                <x-modal title="Add Job Card" maxWidth="lg">
                    <form action="{{ route('vendor-jobs.store') }}" method="POST" class="space-y-5" x-data="{ f: costingFor({}) }">
                        @csrf
                        <div>
                            <x-label :required="true">Vendor</x-label>
                            <x-search-select name="vendor_id" url="{{ route('search.vendors') }}" placeholder="Search vendors..." required />
                        </div>
                        <div>
                            <x-label :required="true">Title</x-label>
                            <x-input name="title" placeholder="e.g. Compound landscaping — Q1 maintenance" required />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label>Estate</x-label>
                                <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="Optional" />
                            </div>
                            <div>
                                <x-label>Unit</x-label>
                                <x-search-select name="unit_id" url="{{ route('search.units') }}" placeholder="Optional" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Job Date</x-label>
                                <x-input type="date" name="job_date" required />
                            </div>
                            <div>
                                <x-label :required="true">Status</x-label>
                                <x-select name="status" x-model="f.status" required>
                                    @foreach(\App\Models\VendorJob::STATUSES as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                        </div>
                        <div>
                            <x-label>Cost</x-label>
                            <x-money-input name="cost" placeholder="0" />
                        </div>
                        @include('vendor-jobs._costing')
                        <div>
                            <x-label>Description / Work Done</x-label>
                            <x-textarea name="description" placeholder="Describe the scope of work..."></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Job Card</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Job Card" maxWidth="lg">
                    <form :action="'{{ url('vendor-jobs') }}/' + editing.id" method="POST" class="space-y-5" x-data="{ f: costingFor(editing) }">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label :required="true">Vendor</x-label>
                            <x-search-select name="vendor_id" url="{{ route('search.vendors') }}" placeholder="Search vendors..." required x-init="setValue(editing.vendor_id, editing.vendor?.name || '')" />
                        </div>
                        <div>
                            <x-label :required="true">Title</x-label>
                            <x-input name="title" x-model="editing.title" required />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label>Estate</x-label>
                                <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="Optional" x-init="setValue(editing.estate_id, editing.estate?.name || '')" />
                            </div>
                            <div>
                                <x-label>Unit</x-label>
                                <x-search-select name="unit_id" url="{{ route('search.units') }}" placeholder="Optional" x-init="setValue(editing.unit_id, editing.unit?.name || '')" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Job Date</x-label>
                                <x-input type="date" name="job_date" x-bind:value="editing.job_date ? editing.job_date.split('T')[0] : ''" required />
                            </div>
                            <div>
                                <x-label :required="true">Status</x-label>
                                <x-select name="status" x-model="f.status" required>
                                    @foreach(\App\Models\VendorJob::STATUSES as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                        </div>
                        <div>
                            <x-label>Cost</x-label>
                            <x-money-input name="cost" placeholder="0" x-init="setValue(editing.cost)" />
                        </div>
                        @include('vendor-jobs._costing')
                        <div>
                            <x-label>Description / Work Done</x-label>
                            <x-textarea name="description" x-model="editing.description"></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Job Card</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
