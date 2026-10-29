<x-app-layout>
    <x-slot name="header">Units</x-slot>

    <x-tip id="units" title="Every unit has its own ID">
        Primora gives each unit an ID that’s printed on invoices. Tenants use it to pay rent and sign in to the tenant portal. A unit shows as Occupied by itself while it has an active lease.
    </x-tip>

    <div class="py-8" x-data="{
        createModal: {{ request()->has('new') ? 'true' : 'false' }},
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
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">All Units</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage individual property units across your estates.</p>
                    @if($unitLimit)
                        <p class="mt-1 text-sm {{ $unitLimit['used'] >= $unitLimit['max'] ? 'text-orange-600 font-medium' : 'text-gray-500' }}">
                            {{ $unitLimit['used'] }} of {{ $unitLimit['max'] }} units used on your subscription.
                            @if($unitLimit['used'] >= $unitLimit['max'])
                                @if(auth()->user()->isLandlordAdmin())<a href="{{ route('subscription.show') }}" class="underline hover:text-orange-700">Upgrade to add more</a>@else Ask an admin to upgrade the subscription to add more. @endif
                            @endif
                        </p>
                    @endif
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
                        <x-input name="search" value="{{ request('search') }}" placeholder="Unit name, ID, estate..." />
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

            {{-- Table (md and up) --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="hidden md:block overflow-x-auto">
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
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                        <div class="flex items-center gap-3">
                                            <x-photo-thumb :url="$unit->photo_url" icon="cube" />
                                            <span><span class="block">{{ $unit->name }}</span><span class="block text-xs font-normal text-gray-500 tabular-nums" title="Tenants sign in with this ID">ID {{ $unit->code }}</span></span>
                                        </div>
                                    </td>
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

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($units as $unit)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-3">
                                        <x-photo-thumb :url="$unit->photo_url" icon="cube" size="w-12 h-12" />
                                        <div class="min-w-0"><p class="text-sm font-medium text-gray-900 break-words">{{ $unit->name }}</p><p class="text-xs text-gray-500 tabular-nums">ID {{ $unit->code }}</p></div>
                                    </div>
                                    <p class="mt-0.5 text-sm text-gray-600">{{ $unit->estate?->name ?? '--' }}</p>
                                </div>
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
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-badge :color="match($unit->status) { 'vacant' => 'green', 'occupied' => 'orange', 'maintenance' => 'gray', default => 'gray' }">{{ ucfirst($unit->status) }}</x-badge>
                            </div>
                            <p class="mt-2 text-sm text-gray-600"><span class="text-gray-500">Description:</span> {{ Str::limit($unit->description, 50) ?? '--' }}</p>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No units found.</p>
                        </div>
                    @endforelse
                </div>

                @if ($units->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $units->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Unit Details" maxWidth="lg">
                    <template x-if="viewing.photo_url">
                        <a :href="viewing.photo_url" target="_blank" rel="noopener" class="block mb-5" title="Open full photo">
                            <img :src="viewing.photo_url" alt="" class="w-full h-56 object-cover rounded-xl border border-gray-200">
                        </a>
                    </template>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Name</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="viewing.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Unit ID</p>
                            <p class="mt-1 text-sm font-medium text-gray-900 tabular-nums" x-text="viewing.code || '--'"></p>
                            <p class="mt-0.5 text-xs text-gray-500">Tenants sign in to the portal with this.</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Estate</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="viewing.estate?.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Address</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="[viewing.estate?.address, viewing.estate?.district].filter(Boolean).join(', ') || '--'"></p>
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
                    <form action="{{ route('units.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Unit A1" required />
                        </div>
                        <div>
                            <x-label>Description</x-label>
                            <x-textarea name="description" placeholder="Optional description..."></x-textarea>
                        </div>
                        <x-photo-input label="Photo (helps identify it)" />
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
                    <form :action="'{{ url('units') }}/' + editing.id" method="POST" enctype="multipart/form-data" class="space-y-5">
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
                        <x-photo-input label="Photo (helps identify it)" current="editing.photo_url" />
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
