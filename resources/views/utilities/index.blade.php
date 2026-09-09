<x-app-layout>
    <x-slot name="header">Utilities</x-slot>

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
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">All Utilities</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage utility types for your properties.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Utility
                </x-button>
            </div>

            {{-- Table --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div>
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($utilities as $utility)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $utility->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $utility->description ?? '--' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({{ Js::from($utility) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                                    <button @click="edit({{ Js::from($utility) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    <form action="{{ route('utilities.destroy', $utility) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this utility?', $el)">
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
                                    <td colspan="3" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No utilities found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($utilities->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">
                        {{ $utilities->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Utility Details" maxWidth="lg">
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Name</p>
                            <p class="text-sm text-gray-900 font-medium" x-text="viewing.name || '--'"></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-xs text-gray-500 mb-1">Description</p>
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
                <x-modal title="Add Utility" maxWidth="lg">
                    <form action="{{ route('utilities.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Electricity" required />
                        </div>
                        <div>
                            <x-label>Description</x-label>
                            <x-textarea name="description" placeholder="Optional description..."></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Utility</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Utility" maxWidth="lg">
                    <form :action="'{{ url('utilities') }}/' + editing.id" method="POST" class="space-y-5">
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
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Utility</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
