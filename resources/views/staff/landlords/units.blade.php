<x-staff-layout>
    <x-slot name="header">{{ $landlord->name }} · Units</x-slot>

    @php
        $estatesByLocation = $estates->groupBy(fn ($e) => $e->location?->name ?? 'No location');
        $statusColor = fn ($s) => match ($s) { 'vacant' => 'green', 'occupied' => 'orange', default => 'gray' };
    @endphp

    <div class="max-w-7xl mx-auto" x-data="{
        createModal: false,
        editModal: false,
        editing: { id: null, name: '', description: '', status: 'vacant', estate_id: '' },
        edit(item) {
            this.editing = { ...item, description: item.description || '', estate_id: String(item.estate_id) };
            this.editModal = true;
        }
    }">
        @include('staff.landlords.partials.workspace-header')

        @if($estates->isEmpty())
            <div class="mb-4 rounded-2xl border border-orange-100 bg-orange-50 px-5 py-4 text-sm text-orange-800">
                This landlord has no estates yet. Units belong to an estate, so
                <a href="{{ route('staff.landlords.estates.index', $landlord) }}" class="font-medium underline">add an estate</a> first.
            </div>
        @endif

        {{-- Filters + add --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-4">
            <form method="GET" action="{{ route('staff.landlords.units.index', $landlord) }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[180px]">
                    <x-label>Search</x-label>
                    <x-input name="search" value="{{ request('search') }}" placeholder="Unit name or description..." />
                </div>
                <div class="w-48">
                    <x-label>Estate</x-label>
                    <x-select name="estate_id">
                        <option value="">All estates</option>
                        @foreach($estatesByLocation as $locationName => $group)
                            <optgroup label="{{ $locationName }}">
                                @foreach($group as $estate)
                                    <option value="{{ $estate->id }}" @selected((string) request('estate_id') === (string) $estate->id)>{{ $estate->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-label>Status</x-label>
                    <x-select name="status">
                        <option value="">All statuses</option>
                        @foreach(['vacant' => 'Vacant', 'occupied' => 'Occupied', 'maintenance' => 'Maintenance'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit">Filter</x-button>
                    @if(request()->hasAny(['search', 'estate_id', 'status']))
                        <a href="{{ route('staff.landlords.units.index', $landlord) }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                    @endif
                </div>
                <div class="ml-auto">
                    @staffcan('properties.manage')<x-button @click="createModal = true" type="button" :disabled="$estates->isEmpty()">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Unit
                    </x-button>@endstaffcan
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Unit</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Estate</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Location</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Leases</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($units as $unit)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 text-sm">
                                    <div class="flex items-center gap-3">
                                        <x-photo-thumb :url="$unit->photo_url" icon="cube" />
                                        <div class="min-w-0">
                                            <p class="font-medium text-gray-900">{{ $unit->name }}</p>
                                            <p class="text-xs text-gray-500 tabular-nums">ID {{ $unit->code }}</p>
                                            @if($unit->description)<p class="text-xs text-gray-500">{{ Str::limit($unit->description, 50) }}</p>@endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $unit->estate?->name ?? '--' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $unit->estate?->location?->name ?? '--' }}</td>
                                <td class="px-6 py-4"><x-badge :color="$statusColor($unit->status)">{{ ucfirst($unit->status) }}</x-badge></td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $unit->leases_count }}</td>
                                <td class="px-6 py-4 text-right">
                                    @staffcan('properties.manage')<x-dropdown align="right" width="48">
                                        <x-slot name="content">
                                            <button @click="edit({{ Js::from($unit->only(['id', 'name', 'description', 'status', 'estate_id', 'photo_url'])) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                            @if($unit->leases_count === 0)
                                                <form action="{{ route('staff.landlords.units.destroy', [$landlord, $unit->id]) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this unit?', $el)">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                                </form>
                                            @endif
                                        </x-slot>
                                    </x-dropdown>@endstaffcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-gray-600">No units found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="md:hidden divide-y divide-gray-100">
                @forelse ($units as $unit)
                    <div class="p-4 flex items-start justify-between gap-3">
                        <x-photo-thumb :url="$unit->photo_url" icon="cube" size="w-14 h-14" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900">{{ $unit->name }}</p>
                            <p class="text-xs text-gray-500 tabular-nums">ID {{ $unit->code }}</p>
                            <p class="mt-0.5 text-sm text-gray-600">{{ $unit->estate?->name ?? '--' }} · {{ $unit->estate?->location?->name ?? '--' }}</p>
                            <div class="mt-2"><x-badge :color="$statusColor($unit->status)">{{ ucfirst($unit->status) }}</x-badge></div>
                        </div>
                        @staffcan('properties.manage')<x-dropdown align="right" width="48">
                            <x-slot name="content">
                                <button @click="edit({{ Js::from($unit->only(['id', 'name', 'description', 'status', 'estate_id', 'photo_url'])) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                @if($unit->leases_count === 0)
                                    <form action="{{ route('staff.landlords.units.destroy', [$landlord, $unit->id]) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this unit?', $el)">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                    </form>
                                @endif
                            </x-slot>
                        </x-dropdown>@endstaffcan
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-gray-600">No units found.</div>
                @endforelse
            </div>

            @if ($units->hasPages())
                <div class="px-4 md:px-6 py-4 border-t border-gray-200">{{ $units->links() }}</div>
            @endif
        </div>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Unit" maxWidth="lg">
                    <form action="{{ route('staff.landlords.units.store', $landlord) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Unit A1" required />
                        </div>
                        <div>
                            <x-label :required="true">Estate</x-label>
                            <x-select name="estate_id" required>
                                <option value="">Select an estate…</option>
                                @foreach($estatesByLocation as $locationName => $group)
                                    <optgroup label="{{ $locationName }}">
                                        @foreach($group as $estate)
                                            <option value="{{ $estate->id }}" @selected((string) request('estate_id') === (string) $estate->id)>{{ $estate->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-label :required="true">Status</x-label>
                            <x-select name="status" required>
                                <option value="vacant">Vacant</option>
                                <option value="maintenance">Maintenance</option>
                            </x-select>
                            <p class="mt-1 text-xs text-gray-500">Units become occupied automatically when the landlord creates a lease.</p>
                        </div>
                        <div>
                            <x-label>Description</x-label>
                            <x-textarea name="description" placeholder="Optional description..."></x-textarea>
                        </div>
                        <x-photo-input label="Photo (helps identify it)" />
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
                    <form :action="'{{ url('staff/landlords/'.$landlord->id.'/units') }}/' + editing.id" method="POST" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" x-model="editing.name" required />
                        </div>
                        <div>
                            <x-label :required="true">Estate</x-label>
                            <x-select name="estate_id" x-model="editing.estate_id" required>
                                @foreach($estatesByLocation as $locationName => $group)
                                    <optgroup label="{{ $locationName }}">
                                        @foreach($group as $estate)
                                            <option value="{{ $estate->id }}">{{ $estate->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </x-select>
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
                            <p class="mt-1 text-xs text-gray-500" x-show="editing.status === 'occupied'">An occupied unit can't change status while it has an active lease.</p>
                        </div>
                        <div>
                            <x-label>Description</x-label>
                            <x-textarea name="description" x-model="editing.description"></x-textarea>
                        </div>
                        <x-photo-input label="Photo (helps identify it)" current="editing.photo_url" />
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Unit</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-staff-layout>
