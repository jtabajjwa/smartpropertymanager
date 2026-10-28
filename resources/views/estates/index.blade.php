<x-app-layout>
    <x-slot name="header">Estates</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        viewModal: false,
        viewing: {},
        editing: {
            id: null,
            name: '',
            description: ''
        },
        view(item) {
            this.viewing = { ...item };
            this.viewModal = true;
        },
        edit(item) {
            this.editing = { ...item };
            this.editModal = true;
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
                    <h3 class="text-lg font-semibold text-gray-900">All Estates</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage estates and their property units.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Estate
                </x-button>
            </div>

            {{-- Table (md and up) --}}
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
                                @php
                                    $occupancyRate = $estate->units_count > 0
                                        ? round(($estate->units->where('status', 'occupied')->count() / $estate->units_count) * 100)
                                        : 0;
                                @endphp
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                        <div class="flex items-center gap-3">
                                            <x-photo-thumb :url="$estate->photo_url" icon="building" />
                                            <span>{{ $estate->name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $estate->fullAddress() ?: '--' }} <x-map-link :estate="$estate" class="ml-1" /></td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $estate->units_count }}</td>
                                    <td class="px-6 py-4">
                                        @if($occupancyRate >= 80)
                                            <x-badge color="green">{{ $occupancyRate }}%</x-badge>
                                        @elseif($occupancyRate >= 50)
                                            <x-badge color="orange">{{ $occupancyRate }}%</x-badge>
                                        @else
                                            <x-badge color="red">{{ $occupancyRate }}%</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({{ Js::from($estate->makeHidden('units')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                                    <button @click="edit({{ Js::from($estate->makeHidden('units')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    @if($estate->units_count === 0)
                                                        <form action="{{ route('estates.destroy', $estate) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this estate?', $el)">
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
                                        <p class="text-sm text-gray-600">No estates found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($estates as $estate)
                        @php
                            $occupancyRate = $estate->units_count > 0
                                ? round(($estate->units->where('status', 'occupied')->count() / $estate->units_count) * 100)
                                : 0;
                        @endphp
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-3">
                                        <x-photo-thumb :url="$estate->photo_url" icon="building" size="w-12 h-12" />
                                        <p class="text-sm font-medium text-gray-900 break-words">{{ $estate->name }}</p>
                                    </div>
                                    <p class="mt-0.5 text-sm text-gray-600">{{ $estate->fullAddress() ?: '--' }} <x-map-link :estate="$estate" class="ml-1" /></p>
                                </div>
                                <x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        <button @click="view({{ Js::from($estate->makeHidden('units')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                        <button @click="edit({{ Js::from($estate->makeHidden('units')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                        @if($estate->units_count === 0)
                                            <form action="{{ route('estates.destroy', $estate) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this estate?', $el)">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                            </form>
                                        @endif
                                    </x-slot>
                                </x-dropdown>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if($occupancyRate >= 80)
                                    <x-badge color="green">{{ $occupancyRate }}%</x-badge>
                                @elseif($occupancyRate >= 50)
                                    <x-badge color="orange">{{ $occupancyRate }}%</x-badge>
                                @else
                                    <x-badge color="red">{{ $occupancyRate }}%</x-badge>
                                @endif
                            </div>
                            <p class="mt-2 text-sm text-gray-600"><span class="text-gray-500">Units:</span> {{ $estate->units_count }}</p>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No estates found.</p>
                        </div>
                    @endforelse
                </div>

                @if ($estates->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $estates->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Estate Details" maxWidth="lg">
                    <template x-if="viewing.photo_url">
                        <a :href="viewing.photo_url" target="_blank" rel="noopener" class="block mb-5" title="Open full photo">
                            <img :src="viewing.photo_url" alt="" class="w-full h-56 object-cover rounded-xl border border-gray-200">
                        </a>
                    </template>
                    <template x-if="viewing.latitude != null && viewing.longitude != null">
                        <div class="mb-5" x-data="mapView(viewing.latitude, viewing.longitude)">
                            <div class="relative z-0"><div x-ref="map" class="h-48 rounded-xl border border-gray-200 bg-gray-100 overflow-hidden"></div></div>
                            <a :href="`https://www.google.com/maps?q=${viewing.latitude},${viewing.longitude}`" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 text-sm text-orange-600 hover:text-orange-700 transition">
                                Open in Google Maps &rarr;
                            </a>
                        </div>
                    </template>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Name</p>
                            <p class="mt-1 text-sm font-medium text-gray-900" x-text="viewing.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Address</p>
                            <p class="mt-1 text-sm text-gray-900" x-text="[viewing.address, viewing.district, viewing.country !== 'Uganda' ? viewing.country : null].filter(Boolean).join(', ') || '--'"></p>
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
                <x-modal title="Add Estate" maxWidth="xl">
                    <form action="{{ route('estates.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Sunset Apartments" required />
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
                    <form :action="'{{ url('estates') }}/' + editing.id" method="POST" enctype="multipart/form-data" class="space-y-5">
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
</x-app-layout>
