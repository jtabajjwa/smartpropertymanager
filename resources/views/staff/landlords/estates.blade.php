<x-staff-layout>
    <x-slot name="header">{{ $landlord->name }} · Estates</x-slot>

    <div class="max-w-7xl mx-auto" x-data="{
        createModal: false,
        editModal: false,
        editing: { id: null, name: '', description: '', address: '', district: '', country: 'Uganda' },
        edit(item) {
            this.editing = { ...item, description: item.description || '', address: item.address || '' };
            this.editModal = true;
        }
    }">
        @include('staff.landlords.partials.workspace-header')

        {{-- Filters + add --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-4">
            <form method="GET" action="{{ route('staff.landlords.estates.index', $landlord) }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[200px]">
                    <x-label>Search</x-label>
                    <x-input name="search" value="{{ request('search') }}" placeholder="Estate name..." />
                </div>
                <div class="w-48">
                    <x-label>District</x-label>
                    <x-select name="district">
                        <option value="">All districts</option>
                        @foreach($districts as $district)
                            <option value="{{ $district }}" @selected(request('district') === $district)>{{ $district }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit">Filter</x-button>
                    @if(request()->hasAny(['search', 'district']))
                        <a href="{{ route('staff.landlords.estates.index', $landlord) }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                    @endif
                </div>
                <div class="ml-auto">
                    @staffcan('properties.manage')<x-button @click="createModal = true" type="button">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add Estate
                    </x-button>@endstaffcan
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Address</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Units</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Occupancy</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($estates as $estate)
                            @php $rate = $estate->units_count > 0 ? round($estate->occupied_count / $estate->units_count * 100) : 0; @endphp
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 text-sm">
                                    <div class="flex items-center gap-3">
                                        <x-photo-thumb :url="$estate->photo_url" icon="building" />
                                        <div class="min-w-0">
                                            <p class="font-medium text-gray-900">{{ $estate->name }}</p>
                                            @if($estate->description)<p class="text-xs text-gray-500">{{ Str::limit($estate->description, 60) }}</p>@endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $estate->fullAddress() ?: '--' }} <x-map-link :estate="$estate" class="ml-1" /></td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $estate->occupied_count }} / {{ $estate->units_count }} let</td>
                                <td class="px-6 py-4">
                                    <x-badge :color="$estate->units_count === 0 ? 'gray' : ($rate >= 80 ? 'green' : ($rate >= 50 ? 'orange' : 'red'))">{{ $estate->units_count === 0 ? 'No units' : $rate.'%' }}</x-badge>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <x-dropdown align="right" width="48">
                                        <x-slot name="content">
                                            <a href="{{ route('staff.landlords.units.index', [$landlord, 'estate_id' => $estate->id]) }}" class="block w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View units</a>
                                            @staffcan('properties.manage')<button @click="edit({{ Js::from($estate->only(['id', 'name', 'description', 'address', 'district', 'country', 'latitude', 'longitude', 'photo_url'])) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>@endstaffcan
                                            @if($estate->units_count === 0)
                                                @staffcan('properties.manage')<form action="{{ route('staff.landlords.estates.destroy', [$landlord, $estate->id]) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this estate?', $el)">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                                </form>@endstaffcan
                                            @endif
                                        </x-slot>
                                    </x-dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-gray-600">No estates found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="md:hidden divide-y divide-gray-100">
                @forelse ($estates as $estate)
                    @php $rate = $estate->units_count > 0 ? round($estate->occupied_count / $estate->units_count * 100) : 0; @endphp
                    <div class="p-4 flex items-start justify-between gap-3">
                        <x-photo-thumb :url="$estate->photo_url" icon="building" size="w-14 h-14" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900">{{ $estate->name }}</p>
                            <p class="mt-0.5 text-sm text-gray-600">{{ $estate->fullAddress() ?: '--' }} <x-map-link :estate="$estate" class="ml-1" /></p>
                            <p class="mt-1 text-xs text-gray-500">{{ $estate->occupied_count }} / {{ $estate->units_count }} units let · {{ $rate }}%</p>
                        </div>
                        <x-dropdown align="right" width="48">
                            <x-slot name="content">
                                <a href="{{ route('staff.landlords.units.index', [$landlord, 'estate_id' => $estate->id]) }}" class="block w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View units</a>
                                @staffcan('properties.manage')<button @click="edit({{ Js::from($estate->only(['id', 'name', 'description', 'address', 'district', 'country', 'latitude', 'longitude', 'photo_url'])) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>@endstaffcan
                                @if($estate->units_count === 0)
                                    @staffcan('properties.manage')<form action="{{ route('staff.landlords.estates.destroy', [$landlord, $estate->id]) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this estate?', $el)">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                    </form>@endstaffcan
                                @endif
                            </x-slot>
                        </x-dropdown>
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-gray-600">No estates found.</div>
                @endforelse
            </div>

            @if ($estates->hasPages())
                <div class="px-4 md:px-6 py-4 border-t border-gray-200">{{ $estates->links() }}</div>
            @endif
        </div>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Estate" maxWidth="xl">
                    <form action="{{ route('staff.landlords.estates.store', $landlord) }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Kololo Apartments" required />
                        </div>
                        <div>
                            <x-label>Description</x-label>
                            <x-textarea name="description" placeholder="Optional description..."></x-textarea>
                        </div>
                        <x-photo-input label="Photo (helps identify it)" />
                        <x-map-picker />
                        <x-estate-address />
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Estate</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Estate" maxWidth="xl">
                    <form :action="'{{ url('staff/landlords/'.$landlord->id.'/estates') }}/' + editing.id" method="POST" enctype="multipart/form-data" class="space-y-4">
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
                        <x-map-picker lat="editing.latitude" lng="editing.longitude" />
                        <x-estate-address model="editing" />
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Estate</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-staff-layout>
