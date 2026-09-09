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
            description: '',
            location_id: ''
        },
        view(item) {
            this.viewing = { ...item };
            this.viewModal = true;
        },
        edit(item) {
            this.editing = { ...item };
            this.editModal = true;
            this.$nextTick(() => {
                this.$dispatch('set-search-select', { name: 'location_id', id: item.location_id, text: item.location?.name || '' });
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
                    <h3 class="text-lg font-semibold text-white">All Estates</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage estates and their property units.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Estate
                </x-button>
            </div>

            {{-- Table --}}
            <div class="bg-gray-900 rounded-2xl shadow-sm border border-white/10">
                <div>
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-white/10">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Location</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Units</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wider">Occupancy</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @forelse ($estates as $estate)
                                @php
                                    $occupancyRate = $estate->units_count > 0
                                        ? round(($estate->units->where('status', 'occupied')->count() / $estate->units_count) * 100)
                                        : 0;
                                @endphp
                                <tr class="hover:bg-white/5 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-white">{{ $estate->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-400">{{ $estate->location?->name ?? '--' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-400">{{ $estate->units_count }}</td>
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
                                                    <button @click="view({{ Js::from($estate->makeHidden('units')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-400 hover:bg-white/5 transition">View</button>
                                                    <button @click="edit({{ Js::from($estate->makeHidden('units')) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-400 hover:bg-white/5 transition">Edit</button>
                                                    @if($estate->units_count === 0)
                                                        <form action="{{ route('estates.destroy', $estate) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this estate?', $el)">
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
                                    <td colspan="5" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-400">No estates found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($estates->hasPages())
                    <div class="px-6 py-4 border-t border-white/10">
                        {{ $estates->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Estate Details" maxWidth="lg">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Name</p>
                            <p class="mt-1 text-sm font-medium text-white" x-text="viewing.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Location</p>
                            <p class="mt-1 text-sm text-white" x-text="viewing.location?.name || '--'"></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Description</p>
                            <p class="mt-1 text-sm text-gray-300 whitespace-pre-line" x-text="viewing.description || 'No description.'"></p>
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
                <x-modal title="Add Estate" maxWidth="lg">
                    <form action="{{ route('estates.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Sunset Apartments" required />
                        </div>
                        <div>
                            <x-label>Description</x-label>
                            <x-textarea name="description" placeholder="Optional description..."></x-textarea>
                        </div>
                        <div>
                            <x-label :required="true">Location</x-label>
                            <x-search-select name="location_id" url="{{ route('search.locations') }}" placeholder="Search locations..." required />
                        </div>
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
                <x-modal title="Edit Estate" maxWidth="lg">
                    <form :action="'{{ url('estates') }}/' + editing.id" method="POST" class="space-y-5">
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
                            <x-label :required="true">Location</x-label>
                            <x-search-select name="location_id" url="{{ route('search.locations') }}" placeholder="Search locations..." required />
                        </div>
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
