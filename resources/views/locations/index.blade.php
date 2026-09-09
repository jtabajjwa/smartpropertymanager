<x-app-layout>
    <x-slot name="header">Locations</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        viewModal: false,
        viewing: {},
        editing: {
            id: null,
            name: '',
            country: '',
            district: '',
            address: ''
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
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-white">All Locations</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage property locations across your portfolio.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Location
                </x-button>
            </div>

            {{-- Table --}}
            <div class="bg-gray-900 rounded-2xl shadow-sm border border-white/10">
                <div>
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Country</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">District</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Address</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Estates</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @forelse ($locations as $location)
                                <tr class="hover:bg-white/5 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-white">{{ $location->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-400">{{ $location->country }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-400">{{ $location->district }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-400">{{ $location->address }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-400">{{ $location->estates_count }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({{ Js::from($location) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-400 hover:bg-white/5 transition">View</button>
                                                    <button @click="edit({{ Js::from($location) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-400 hover:bg-white/5 transition">Edit</button>
                                                    @if($location->estates_count === 0)
                                                        <form action="{{ route('locations.destroy', $location) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this location?', $el)">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-white/5 transition">Delete</button>
                                                        </form>
                                                    @endif
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-400">No locations found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($locations->hasPages())
                    <div class="px-6 py-4 border-t border-white/10">
                        {{ $locations->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Location Details" maxWidth="lg">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Name</p>
                            <p class="mt-1 text-sm font-medium text-white" x-text="viewing.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Country</p>
                            <p class="mt-1 text-sm text-white" x-text="viewing.country || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">District</p>
                            <p class="mt-1 text-sm text-white" x-text="viewing.district || '--'"></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Address</p>
                            <p class="mt-1 text-sm text-white" x-text="viewing.address || '--'"></p>
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
                <x-modal title="Add Location" maxWidth="lg">
                    <form action="{{ route('locations.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Kampala Central" required />
                        </div>
                        <div>
                            <x-label :required="true">Country</x-label>
                            <x-input name="country" placeholder="e.g. Uganda" required />
                        </div>
                        <div>
                            <x-label :required="true">District</x-label>
                            <x-input name="district" placeholder="e.g. Kampala" required />
                        </div>
                        <div>
                            <x-label :required="true">Address</x-label>
                            <x-input name="address" placeholder="e.g. Plot 12, Bombo Road" required />
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
                    <form :action="'{{ url('locations') }}/' + editing.id" method="POST" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" x-model="editing.name" required />
                        </div>
                        <div>
                            <x-label :required="true">Country</x-label>
                            <x-input name="country" x-model="editing.country" required />
                        </div>
                        <div>
                            <x-label :required="true">District</x-label>
                            <x-input name="district" x-model="editing.district" required />
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
</x-app-layout>
