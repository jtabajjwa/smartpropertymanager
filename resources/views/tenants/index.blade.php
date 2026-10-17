<x-app-layout>
    <x-slot name="header">Tenants</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        viewModal: false,
        viewing: {},
        editing: {
            id: null,
            number: '',
            type: '',
            name: '',
            email: '',
            primary_phone: '',
            secondary_phone: '',
            id_type: '',
            id_number: '',
            photo: '',
            lc_letter: '',
            id_scan: '',
            emergency_contact_name: '',
            emergency_contact_relationship: '',
            emergency_contact_phone: '',
            emergency_contact_email: '',
            emergency_contact_address: '',
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
                    <h3 class="text-lg font-semibold text-gray-900">All Tenants</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage tenants and their contact information.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Tenant
                </x-button>
            </div>

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('tenants.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Name, number, email, phone..." />
                    </div>
                    <div class="w-40">
                        <x-label>Type</x-label>
                        <x-select name="type">
                            <option value="">All Types</option>
                            <option value="individual" {{ request('type') === 'individual' ? 'selected' : '' }}>Individual</option>
                            <option value="company" {{ request('type') === 'company' ? 'selected' : '' }}>Company</option>
                        </x-select>
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'type']))
                            <a href="{{ route('tenants.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
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
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Number</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Phone</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($tenants as $tenant)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $tenant->number }}</td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $tenant->name }}</td>
                                    <td class="px-6 py-4">
                                        <x-badge :color="$tenant->type === 'individual' ? 'blue' : 'orange'">{{ ucfirst($tenant->type) }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $tenant->email ?? '--' }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $tenant->primary_phone ?? '--' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="view({{ Js::from($tenant) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                                    <button @click="edit({{ Js::from($tenant) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    @if($tenant->leases_count === 0 && $tenant->payments_count === 0)
                                                        <form action="{{ route('tenants.destroy', $tenant) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this tenant?', $el)">
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
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No tenants found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($tenants as $tenant)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 break-words">{{ $tenant->name }}</p>
                                    <p class="mt-0.5 text-sm text-gray-600">{{ $tenant->number }}</p>
                                    <p class="text-sm text-gray-600 break-words">{{ $tenant->email ?? '--' }}</p>
                                    <p class="text-sm text-gray-600">{{ $tenant->primary_phone ?? '--' }}</p>
                                </div>
                                <x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        <button @click="view({{ Js::from($tenant) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">View</button>
                                        <button @click="edit({{ Js::from($tenant) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                        @if($tenant->leases_count === 0 && $tenant->payments_count === 0)
                                            <form action="{{ route('tenants.destroy', $tenant) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to delete this tenant?', $el)">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                            </form>
                                        @endif
                                    </x-slot>
                                </x-dropdown>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-badge :color="$tenant->type === 'individual' ? 'blue' : 'orange'">{{ ucfirst($tenant->type) }}</x-badge>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No tenants found.</p>
                        </div>
                    @endforelse
                </div>

                @if ($tenants->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $tenants->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- View Modal --}}
        <template x-if="viewModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) viewModal = false })">
                <x-modal title="Tenant Details" maxWidth="2xl">
                    <div class="space-y-6">

                        {{-- Personal Information --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Personal Information</h4>
                            <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                                <div>
                                    <p class="text-xs text-gray-500">Number</p>
                                    <p class="text-sm text-gray-900 font-medium" x-text="viewing.number || '--'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Type</p>
                                    <p class="text-sm text-gray-900 capitalize" x-text="viewing.type || '--'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Name</p>
                                    <p class="text-sm text-gray-900 font-medium" x-text="viewing.name || '--'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Email</p>
                                    <p class="text-sm text-gray-900" x-text="viewing.email || '--'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Primary Phone</p>
                                    <p class="text-sm text-gray-900" x-text="viewing.primary_phone || '--'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Secondary Phone</p>
                                    <p class="text-sm text-gray-900" x-text="viewing.secondary_phone || '--'"></p>
                                </div>
                            </div>
                        </div>

                        {{-- Identification --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Identification</h4>
                            <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                                <div>
                                    <p class="text-xs text-gray-500">ID Type</p>
                                    <p class="text-sm text-gray-900" x-text="formatLabel(viewing.id_type)"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">ID Number</p>
                                    <p class="text-sm text-gray-900" x-text="viewing.id_number || '--'"></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-4 mt-4">
                                <div>
                                    <p class="text-xs text-gray-500 mb-2">Photo</p>
                                    <template x-if="viewing.photo">
                                        <a :href="'/storage/' + viewing.photo" target="_blank">
                                            <img :src="'/storage/' + viewing.photo" alt="Tenant Photo" class="w-full h-32 object-cover rounded-xl border border-gray-200 hover:border-orange-500 transition" />
                                        </a>
                                    </template>
                                    <template x-if="!viewing.photo">
                                        <div class="w-full h-32 rounded-xl border border-gray-200 bg-gray-50 flex items-center justify-center">
                                            <p class="text-xs text-gray-500">No photo</p>
                                        </div>
                                    </template>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-2">ID Scan</p>
                                    <template x-if="viewing.id_scan">
                                        <a :href="'/storage/' + viewing.id_scan" target="_blank">
                                            <img :src="'/storage/' + viewing.id_scan" alt="ID Scan" class="w-full h-32 object-cover rounded-xl border border-gray-200 hover:border-orange-500 transition" />
                                        </a>
                                    </template>
                                    <template x-if="!viewing.id_scan">
                                        <div class="w-full h-32 rounded-xl border border-gray-200 bg-gray-50 flex items-center justify-center">
                                            <p class="text-xs text-gray-500">No scan</p>
                                        </div>
                                    </template>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-2">LC Letter</p>
                                    <template x-if="viewing.lc_letter">
                                        <a :href="'/storage/' + viewing.lc_letter" target="_blank">
                                            <img :src="'/storage/' + viewing.lc_letter" alt="LC Letter" class="w-full h-32 object-cover rounded-xl border border-gray-200 hover:border-orange-500 transition" />
                                        </a>
                                    </template>
                                    <template x-if="!viewing.lc_letter">
                                        <div class="w-full h-32 rounded-xl border border-gray-200 bg-gray-50 flex items-center justify-center">
                                            <p class="text-xs text-gray-500">No letter</p>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        {{-- Emergency Contact --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Emergency Contact</h4>
                            <div class="grid grid-cols-2 gap-x-6 gap-y-3">
                                <div>
                                    <p class="text-xs text-gray-500">Contact Name</p>
                                    <p class="text-sm text-gray-900" x-text="viewing.emergency_contact_name || '--'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Relationship</p>
                                    <p class="text-sm text-gray-900" x-text="formatLabel(viewing.emergency_contact_relationship)"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Contact Phone</p>
                                    <p class="text-sm text-gray-900" x-text="viewing.emergency_contact_phone || '--'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Contact Email</p>
                                    <p class="text-sm text-gray-900" x-text="viewing.emergency_contact_email || '--'"></p>
                                </div>
                                <div class="col-span-2">
                                    <p class="text-xs text-gray-500">Contact Address</p>
                                    <p class="text-sm text-gray-900" x-text="viewing.emergency_contact_address || '--'"></p>
                                </div>
                            </div>
                        </div>

                        {{-- Notes --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Notes</h4>
                            <p class="text-sm text-gray-700 whitespace-pre-line" x-text="viewing.notes || 'No notes.'"></p>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="viewModal = false">Close</x-button>
                            <x-button type="button" @click="viewModal = false; edit(viewing)">Edit Tenant</x-button>
                        </div>
                    </div>
                </x-modal>
            </div>
        </template>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Tenant" maxWidth="2xl">
                    <form action="{{ route('tenants.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                        @csrf

                        {{-- Personal Information --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Personal Information</h4>
                            <div class="space-y-4">
                                <div>
                                    <x-label :required="true">Type</x-label>
                                    <x-select name="type" required>
                                        <option value="individual">Individual</option>
                                        <option value="company">Company</option>
                                    </x-select>
                                </div>
                                <div>
                                    <x-label :required="true">Name</x-label>
                                    <x-input name="name" placeholder="Full name" required />
                                </div>
                                <div>
                                    <x-label :required="true">Email</x-label>
                                    <x-input type="email" name="email" placeholder="email@example.com" required />
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">Primary Phone</x-label>
                                        <x-input name="primary_phone" placeholder="+256..." required />
                                    </div>
                                    <div>
                                        <x-label>Secondary Phone</x-label>
                                        <x-input name="secondary_phone" placeholder="+256..." />
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Identification --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Identification</h4>
                            <div class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">ID Type</x-label>
                                        <x-select name="id_type" required>
                                            <option value="">Select ID Type</option>
                                            <option value="passport">Passport</option>
                                            <option value="national_id">National ID</option>
                                            <option value="drivers_license">Driver's License</option>
                                            <option value="business_registration">Business Registration</option>
                                            <option value="other">Other</option>
                                        </x-select>
                                    </div>
                                    <div>
                                        <x-label :required="true">ID Number</x-label>
                                        <x-input name="id_number" placeholder="ID number" required />
                                    </div>
                                </div>
                                <div>
                                    <x-label>Photo</x-label>
                                    <input type="file" name="photo" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label>LC Letter</x-label>
                                        <input type="file" name="lc_letter" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                                    </div>
                                    <div>
                                        <x-label>ID Scan</x-label>
                                        <input type="file" name="id_scan" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Emergency Contact --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Emergency Contact</h4>
                            <div class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">Contact Name</x-label>
                                        <x-input name="emergency_contact_name" placeholder="Full name" required />
                                    </div>
                                    <div>
                                        <x-label :required="true">Relationship</x-label>
                                        <x-select name="emergency_contact_relationship" required>
                                            <option value="">Select Relationship</option>
                                            <option value="family">Family</option>
                                            <option value="friend">Friend</option>
                                            <option value="colleague">Colleague</option>
                                            <option value="other">Other</option>
                                        </x-select>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">Contact Phone</x-label>
                                        <x-input name="emergency_contact_phone" placeholder="+256..." required />
                                    </div>
                                    <div>
                                        <x-label>Contact Email</x-label>
                                        <x-input type="email" name="emergency_contact_email" placeholder="email@example.com" />
                                    </div>
                                </div>
                                <div>
                                    <x-label>Contact Address</x-label>
                                    <x-input name="emergency_contact_address" placeholder="Physical address" />
                                </div>
                            </div>
                        </div>

                        {{-- Notes --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Notes</h4>
                            <div>
                                <x-label>Notes</x-label>
                                <x-textarea name="notes" rows="3" placeholder="Any additional notes..."></x-textarea>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Tenant</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Tenant" maxWidth="2xl">
                    <form :action="'{{ url('tenants') }}/' + editing.id" method="POST" enctype="multipart/form-data" class="space-y-6">
                        @csrf
                        @method('PUT')

                        {{-- Personal Information --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Personal Information</h4>
                            <div class="space-y-4">
                                <div>
                                    <x-label>Number</x-label>
                                    <x-input name="number" x-model="editing.number" readonly class="opacity-50" />
                                </div>
                                <div>
                                    <x-label :required="true">Type</x-label>
                                    <x-select name="type" x-model="editing.type" required>
                                        <option value="individual">Individual</option>
                                        <option value="company">Company</option>
                                    </x-select>
                                </div>
                                <div>
                                    <x-label :required="true">Name</x-label>
                                    <x-input name="name" x-model="editing.name" required />
                                </div>
                                <div>
                                    <x-label :required="true">Email</x-label>
                                    <x-input type="email" name="email" x-model="editing.email" required />
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">Primary Phone</x-label>
                                        <x-input name="primary_phone" x-model="editing.primary_phone" required />
                                    </div>
                                    <div>
                                        <x-label>Secondary Phone</x-label>
                                        <x-input name="secondary_phone" x-model="editing.secondary_phone" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Identification --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Identification</h4>
                            <div class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">ID Type</x-label>
                                        <x-select name="id_type" x-model="editing.id_type" required>
                                            <option value="">Select ID Type</option>
                                            <option value="passport">Passport</option>
                                            <option value="national_id">National ID</option>
                                            <option value="drivers_license">Driver's License</option>
                                            <option value="business_registration">Business Registration</option>
                                            <option value="other">Other</option>
                                        </x-select>
                                    </div>
                                    <div>
                                        <x-label :required="true">ID Number</x-label>
                                        <x-input name="id_number" x-model="editing.id_number" required />
                                    </div>
                                </div>
                                <div>
                                    <x-label>Photo</x-label>
                                    <template x-if="editing.photo">
                                        <p class="text-xs text-gray-500 mb-1">Current: <span x-text="editing.photo.split('/').pop()" class="text-orange-600"></span></p>
                                    </template>
                                    <input type="file" name="photo" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label>LC Letter</x-label>
                                        <template x-if="editing.lc_letter">
                                            <p class="text-xs text-gray-500 mb-1">Current: <span x-text="editing.lc_letter.split('/').pop()" class="text-orange-600"></span></p>
                                        </template>
                                        <input type="file" name="lc_letter" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                                    </div>
                                    <div>
                                        <x-label>ID Scan</x-label>
                                        <template x-if="editing.id_scan">
                                            <p class="text-xs text-gray-500 mb-1">Current: <span x-text="editing.id_scan.split('/').pop()" class="text-orange-600"></span></p>
                                        </template>
                                        <input type="file" name="id_scan" accept="image/*" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Emergency Contact --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Emergency Contact</h4>
                            <div class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">Contact Name</x-label>
                                        <x-input name="emergency_contact_name" x-model="editing.emergency_contact_name" required />
                                    </div>
                                    <div>
                                        <x-label :required="true">Relationship</x-label>
                                        <x-select name="emergency_contact_relationship" x-model="editing.emergency_contact_relationship" required>
                                            <option value="">Select Relationship</option>
                                            <option value="family">Family</option>
                                            <option value="friend">Friend</option>
                                            <option value="colleague">Colleague</option>
                                            <option value="other">Other</option>
                                        </x-select>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <x-label :required="true">Contact Phone</x-label>
                                        <x-input name="emergency_contact_phone" x-model="editing.emergency_contact_phone" required />
                                    </div>
                                    <div>
                                        <x-label>Contact Email</x-label>
                                        <x-input type="email" name="emergency_contact_email" x-model="editing.emergency_contact_email" />
                                    </div>
                                </div>
                                <div>
                                    <x-label>Contact Address</x-label>
                                    <x-input name="emergency_contact_address" x-model="editing.emergency_contact_address" />
                                </div>
                            </div>
                        </div>

                        {{-- Notes --}}
                        <div>
                            <h4 class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Notes</h4>
                            <div>
                                <x-label>Notes</x-label>
                                <x-textarea name="notes" x-model="editing.notes" rows="3"></x-textarea>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Tenant</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
