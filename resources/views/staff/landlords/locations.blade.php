<x-staff-layout>
    <x-slot name="header">{{ $landlord->name }} · Locations</x-slot>

    <div class="max-w-7xl mx-auto" x-data="{
        createModal: false,
        editModal: false,
        editing: { id: null, name: '', country: '', district: '', address: '' },
        edit(item) {
            this.editing = { ...item };
            this.editModal = true;
        }
    }">
        @include('staff.landlords.partials.workspace-header')

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
            <p class="text-sm text-gray-500">Locations group a landlord's estates by area. Every estate belongs to one.</p>
            @staffcan('properties.manage')<x-button @click="createModal = true" type="button">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add Location
            </x-button>@endstaffcan
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">District</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Country</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Address</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Estates</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($locations as $location)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $location->name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $location->district }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $location->country }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $location->address }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600">{{ $location->estates_count }}</td>
                                <td class="px-6 py-4 text-right">
                                    @staffcan('properties.manage')<x-dropdown align="right" width="48">
                                        <x-slot name="content">
                                            <button @click="edit({{ Js::from($location->only(['id', 'name', 'country', 'district', 'address'])) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                            @if($location->estates_count === 0)
                                                <form action="{{ route('staff.landlords.locations.destroy', [$landlord, $location->id]) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this location?', $el)">
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
                            <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-gray-600">No locations yet. Add one to start creating estates.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="md:hidden divide-y divide-gray-100">
                @forelse ($locations as $location)
                    <div class="p-4 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900">{{ $location->name }}</p>
                            <p class="mt-0.5 text-sm text-gray-600">{{ $location->district }}, {{ $location->country }}</p>
                            <p class="text-xs text-gray-500">{{ $location->address }} · {{ $location->estates_count }} {{ Str::plural('estate', $location->estates_count) }}</p>
                        </div>
                        @staffcan('properties.manage')<x-dropdown align="right" width="48">
                            <x-slot name="content">
                                <button @click="edit({{ Js::from($location->only(['id', 'name', 'country', 'district', 'address'])) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                @if($location->estates_count === 0)
                                    <form action="{{ route('staff.landlords.locations.destroy', [$landlord, $location->id]) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this location?', $el)">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                    </form>
                                @endif
                            </x-slot>
                        </x-dropdown>@endstaffcan
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-gray-600">No locations yet.</div>
                @endforelse
            </div>

            @if ($locations->hasPages())
                <div class="px-4 md:px-6 py-4 border-t border-gray-200">{{ $locations->links() }}</div>
            @endif
        </div>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Location" maxWidth="lg">
                    <form action="{{ route('staff.landlords.locations.store', $landlord) }}" method="POST" class="space-y-4">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Ntinda" required />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">District</x-label>
                                <x-input name="district" placeholder="e.g. Kampala" required />
                            </div>
                            <div>
                                <x-label :required="true">Country</x-label>
                                <x-input name="country" value="Uganda" required />
                            </div>
                        </div>
                        <div>
                            <x-label :required="true">Address</x-label>
                            <x-input name="address" placeholder="Street / plot" required />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Location</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Location" maxWidth="lg">
                    <form :action="'{{ url('staff/landlords/'.$landlord->id.'/locations') }}/' + editing.id" method="POST" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" x-model="editing.name" required />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">District</x-label>
                                <x-input name="district" x-model="editing.district" required />
                            </div>
                            <div>
                                <x-label :required="true">Country</x-label>
                                <x-input name="country" x-model="editing.country" required />
                            </div>
                        </div>
                        <div>
                            <x-label :required="true">Address</x-label>
                            <x-input name="address" x-model="editing.address" required />
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Location</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-staff-layout>
