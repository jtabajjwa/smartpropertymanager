<x-app-layout>
    <x-slot name="header">Units</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        viewModal: false,
        viewing: {},
        editing: {
            id: null,
            name: '',
            description: '',
            status: '',
            estate_id: ''
        },
        view(item) {
            this.viewing = { ...item };
            this.viewModal = true;
        },
        edit(item) {
            this.editing = { ...item };
            this.editModal = true;
            this.$nextTick(() => { this.$dispatch('set-search-select', { name: 'estate_id', id: item.estate_id, text: item.estate?.name || '' }) });
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
                    <h3 class="text-lg font-semibold text-gray-900">All Units</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage individual property units across your estates.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Unit
                </x-button>
            </div>

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('units.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Unit name, estate..." />
                    </div>
                    <div class="w-40">
                        <x-label>Status</x-label>
                        <x-select name="status">
                            <option value="">All Statuses</option>
                            <option value="vacant" {{ request('status') === 'vacant' ? 'selected' : '' }}>Vacant</option>
                            <option value="occupied" {{ request('status') === 'occupied' ? 'selected' : '' }}>Occupied</option>
                            <option value="maintenance" {{ request('status') === 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                        </x-select>
                    </div>
                    <div class="w-44">
                        <x-label>Estate</x-label>
                        <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="All Estates" />
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'status', 'estate_id']))
                            <a href="{{ route('units.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
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
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Estate</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($units as $unit)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $unit->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $unit->estate?->name ?? '--' }}</td>
                                    <td class="px-6 py-4">
                                        <x-badge :color="match($unit->status) { 'vacant' => 'green', 'occupied' => 'orange', 'maintenance' => 'gray', default => 'gray' }">{{ ucfirst($unit->status) }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ Str::limit($unit->description, 50) ?? '--' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({{ Js::from($unit) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                                    <button @click="edit({{ Js::from($unit) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    @if($unit->leases_count === 0)
                                                        <form action="{{ route('units.destroy', $unit) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this unit?', $el)">
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
                                    <td colspan="5" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No units found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($units->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $units->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Unit Details" maxWidth="lg">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Name</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="viewing.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Estate</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="viewing.estate?.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Location</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="viewing.estate?.location?.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Status</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="formatLabel(viewing.status)"></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Description</p>
                            <p class="mt-1 text-sm text-gray-700 whitespace-pre-line" x-text="viewing.description || 'No description.'"></p>
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
                <x-modal title="Add Unit" maxWidth="lg">
                    <form action="{{ route('units.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Unit A1" required />
                        </div>
                        <div>
                            <x-label>Description</x-label>
                            <x-textarea name="description" placeholder="Optional description..."></x-textarea>
                        </div>
                        <div>
                            <x-label :required="true">Status</x-label>
                            <x-select name="status" required>
                                <option value="vacant">Vacant</option>
                                <option value="maintenance">Maintenance</option>
                            </x-select>
                        </div>
                        <div>
                            <x-label :required="true">Estate</x-label>
                            <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="Search estates..." required />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Unit</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Unit" maxWidth="lg">
                    <form :action="'{{ url('units') }}/' + editing.id" method="POST" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" x-model="editing.name" required />
                        </div>
                        <div>
                            <x-label>Description</x-label>
                            <x-textarea name="description" x-model="editing.description"></x-textarea>
                        </div>
                        <div>
                            <x-label :required="true">Status</x-label>
                            <x-select name="status" x-model="editing.status" required>
                                <option value="vacant">Vacant</option>
                                <template x-if="editing.status === 'occupied'">
                                    <option value="occupied">Occupied</option>
                                </template>
                                <option value="maintenance">Maintenance</option>
                            </x-select>
                        </div>
                        <div>
                            <x-label :required="true">Estate</x-label>
                            <x-search-select name="estate_id" url="{{ route('search.estates') }}" placeholder="Search estates..." required />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Unit</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
