<x-app-layout>
    <x-slot name="header">Vendors</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        viewModal: false,
        viewing: {},
        editing: {
            id: null,
            name: '',
            category: 'other',
            contact_person: '',
            phone: '',
            email: '',
            address: '',
            notes: ''
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
                    <h3 class="text-lg font-semibold text-gray-900">All Vendors</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage service providers — electricians, plumbers, security, and more.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Vendor
                </x-button>
            </div>

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('vendors.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Name, contact, phone, email..." />
                    </div>
                    <div class="w-48">
                        <x-label>Category</x-label>
                        <x-select name="category">
                            <option value="">All Categories</option>
                            @foreach(\App\Models\Vendor::CATEGORIES as $value => $label)
                                <option value="{{ $value }}" {{ request('category') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'category']))
                            <a href="{{ route('vendors.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
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
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Contact Person</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Phone</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($vendors as $vendor)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $vendor->name }}</td>
                                    <td class="px-6 py-4">
                                        <x-badge color="blue">{{ $vendor->category_label }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $vendor->contact_person ?? '--' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $vendor->phone ?? '--' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $vendor->email ?? '--' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({{ Js::from($vendor) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                                    <button @click="edit({{ Js::from($vendor) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    <form action="{{ route('vendors.destroy', $vendor) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this vendor?', $el)">
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
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No vendors found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($vendors as $vendor)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 break-words">{{ $vendor->name }}</p>
                                    <p class="mt-0.5 text-sm text-gray-600 break-words">{{ $vendor->contact_person ?? '--' }}</p>
                                </div>
                                <x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        <button @click="view({{ Js::from($vendor) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                        <button @click="edit({{ Js::from($vendor) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                        <form action="{{ route('vendors.destroy', $vendor) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this vendor?', $el)">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                        </form>
                                    </x-slot>
                                </x-dropdown>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-badge color="blue">{{ $vendor->category_label }}</x-badge>
                            </div>
                            <div class="mt-3 space-y-1">
                                <p class="text-sm text-gray-600"><span class="text-gray-500">Phone:</span> {{ $vendor->phone ?? '--' }}</p>
                                <p class="text-sm text-gray-600"><span class="text-gray-500">Email:</span> {{ $vendor->email ?? '--' }}</p>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No vendors found.</p>
                        </div>
                    @endforelse
                </div>

                @if ($vendors->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $vendors->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Vendor Details" maxWidth="lg">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Name</p>
                            <p class="text-sm text-gray-900 font-medium" x-text="viewing.name || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Category</p>
                            <p class="text-sm text-gray-900" x-text="formatLabel(viewing.category)"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Contact Person</p>
                            <p class="text-sm text-gray-900" x-text="viewing.contact_person || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Phone</p>
                            <p class="text-sm text-gray-900" x-text="viewing.phone || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Email</p>
                            <p class="text-sm text-gray-900" x-text="viewing.email || '--'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Address</p>
                            <p class="text-sm text-gray-900" x-text="viewing.address || '--'"></p>
                        </div>
                        <div class="col-span-1 sm:col-span-2">
                            <p class="text-xs text-gray-500 mb-1">Notes</p>
                            <p x-text="viewing.notes || 'No notes.'" class="text-sm text-gray-700 whitespace-pre-line"></p>
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
                <x-modal title="Add Vendor" maxWidth="lg">
                    <form action="{{ route('vendors.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="e.g. Acme Electrical Services" required />
                        </div>
                        <div>
                            <x-label :required="true">Category</x-label>
                            <x-select name="category" required>
                                @foreach(\App\Models\Vendor::CATEGORIES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label>Contact Person</x-label>
                                <x-input name="contact_person" placeholder="Full name" />
                            </div>
                            <div>
                                <x-label>Phone</x-label>
                                <x-input name="phone" placeholder="0771234567" />
                            </div>
                        </div>
                        <div>
                            <x-label>Email</x-label>
                            <x-input type="email" name="email" placeholder="vendor@example.com" />
                        </div>
                        <div>
                            <x-label>Address</x-label>
                            <x-input name="address" placeholder="Optional physical address" />
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" placeholder="Optional notes..."></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Vendor</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Vendor" maxWidth="lg">
                    <form :action="'{{ url('vendors') }}/' + editing.id" method="POST" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" x-model="editing.name" required />
                        </div>
                        <div>
                            <x-label :required="true">Category</x-label>
                            <x-select name="category" x-model="editing.category" required>
                                @foreach(\App\Models\Vendor::CATEGORIES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label>Contact Person</x-label>
                                <x-input name="contact_person" x-model="editing.contact_person" />
                            </div>
                            <div>
                                <x-label>Phone</x-label>
                                <x-input name="phone" x-model="editing.phone" />
                            </div>
                        </div>
                        <div>
                            <x-label>Email</x-label>
                            <x-input type="email" name="email" x-model="editing.email" />
                        </div>
                        <div>
                            <x-label>Address</x-label>
                            <x-input name="address" x-model="editing.address" />
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" x-model="editing.notes"></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Vendor</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
