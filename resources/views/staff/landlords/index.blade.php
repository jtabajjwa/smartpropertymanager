<x-staff-layout>
    <x-slot name="header">Landlords</x-slot>

    <div class="py-2" x-data="{
        createModal: false,
        editModal: false,
        editing: {
            id: null,
            company_name: '',
            trading_name: '',
            email: '',
            phone: '',
            address: '',
        },
        edit(item) {
            this.editing = {
                id: item.id,
                company_name: item.name,
                trading_name: item.trading_name || '',
                email: item.contact_email || '',
                phone: item.contact_phone || '',
                address: item.address || '',
            };
            this.editModal = true;
        }
    }">
        <div class="max-w-7xl mx-auto">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">All Landlords</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage landlord organizations signed up to Primora.</p>
                </div>
                @staffcan('landlords.create')<x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Landlord
                </x-button>@endstaffcan
            </div>

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('staff.landlords.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Company, email, phone..." />
                    </div>
                    <div class="w-40">
                        <x-label>Status</x-label>
                        <x-select name="status">
                            <option value="">All Statuses</option>
                            <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                        </x-select>
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'status']))
                            <a href="{{ route('staff.landlords.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
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
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Company</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Contact Email</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Phone</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Portfolio</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Subscription</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($landlords as $landlord)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                        <a href="{{ route('staff.landlords.show', $landlord) }}" class="hover:text-orange-600 transition">{{ $landlord->name }}</a>
                                        @if($landlord->trading_name)
                                            <span class="text-gray-500 font-normal">({{ $landlord->trading_name }})</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $landlord->contact_email }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $landlord->contact_phone }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap">
                                        {{ $landlord->units_count }} {{ Str::plural('unit', $landlord->units_count) }}
                                        <span class="block text-xs text-gray-400">
                                            {{ $landlord->estates_count }} {{ Str::plural('estate', $landlord->estates_count) }}@if($landlord->units_count > 0) · {{ round($landlord->occupied_units_count / $landlord->units_count * 100) }}% let @endif
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <x-badge color="gray">{{ ucfirst($landlord->subscription_status) }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4">
                                        <x-badge :color="$landlord->status === 'active' ? 'green' : 'red'">{{ ucfirst($landlord->status) }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    @staffcan('portal.access')<form action="{{ route('staff.landlords.portal', $landlord) }}" method="POST">
                                                        @csrf
                                                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-gray-900 font-medium hover:bg-gray-50 transition">Open landlord portal</button>
                                                    </form>@endstaffcan
                                                    @staffcan('properties.view')<a href="{{ route('staff.landlords.show', $landlord) }}" class="block w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">Manage properties</a>@endstaffcan
                                                    @staffcan('landlords.edit')<button @click="edit({{ Js::from($landlord) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>@endstaffcan
                                                    @staffcan('landlords.suspend')@if($landlord->status === 'active')
                                                        <form action="{{ route('staff.landlords.suspend', $landlord) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to suspend this landlord?', $el)">
                                                            @csrf
                                                            @method('PATCH')
                                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Suspend</button>
                                                        </form>
                                                    @else
                                                        <form action="{{ route('staff.landlords.reactivate', $landlord) }}" method="POST" @submit.prevent="$store.confirm.open('Reactivate this landlord?', $el)">
                                                            @csrf
                                                            @method('PATCH')
                                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-green-600 hover:bg-gray-50 transition">Reactivate</button>
                                                        </form>
                                                    @endif
@endstaffcan
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No landlords found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($landlords as $landlord)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 break-words">
                                        {{ $landlord->name }}
                                        @if($landlord->trading_name)
                                            <span class="text-gray-500 font-normal">({{ $landlord->trading_name }})</span>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-sm text-gray-600 break-words">{{ $landlord->contact_email }}</p>
                                    <p class="text-sm text-gray-600">{{ $landlord->contact_phone }}</p>
                                </div>
                                <x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        @staffcan('portal.access')<form action="{{ route('staff.landlords.portal', $landlord) }}" method="POST">
                                                        @csrf
                                                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-gray-900 font-medium hover:bg-gray-50 transition">Open landlord portal</button>
                                                    </form>@endstaffcan
                                                    @staffcan('properties.view')<a href="{{ route('staff.landlords.show', $landlord) }}" class="block w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-gray-50 transition">Manage properties</a>@endstaffcan
                                                    @staffcan('landlords.edit')<button @click="edit({{ Js::from($landlord) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>@endstaffcan
                                        @staffcan('landlords.suspend')@if($landlord->status === 'active')
                                            <form action="{{ route('staff.landlords.suspend', $landlord) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to suspend this landlord?', $el)">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Suspend</button>
                                            </form>
                                        @else
                                            <form action="{{ route('staff.landlords.reactivate', $landlord) }}" method="POST" @submit.prevent="$store.confirm.open('Reactivate this landlord?', $el)">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-green-600 hover:bg-gray-50 transition">Reactivate</button>
                                            </form>
                                        @endif
@endstaffcan
                                    </x-slot>
                                </x-dropdown>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-badge color="orange">{{ $landlord->units_count }} {{ Str::plural('unit', $landlord->units_count) }}</x-badge>
                                <x-badge color="gray">{{ ucfirst($landlord->subscription_status) }}</x-badge>
                                <x-badge :color="$landlord->status === 'active' ? 'green' : 'red'">{{ ucfirst($landlord->status) }}</x-badge>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No landlords found.</p>
                        </div>
                    @endforelse
                </div>

                @if ($landlords->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $landlords->links() }}
                    </div>
                @endif
            </div>
        </div>

        @staffcan('landlords.create')
        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Landlord" maxWidth="lg">
                    <form action="{{ route('staff.landlords.store') }}" method="POST" class="space-y-4">
                        @csrf

                        <div>
                            <x-label :required="true">Company Name</x-label>
                            <x-input name="company_name" placeholder="e.g. Acme Properties Ltd" required />
                        </div>

                        <div>
                            <x-label>Trading Name</x-label>
                            <x-input name="trading_name" placeholder="Optional trading name" />
                        </div>

                        <div>
                            <x-label>Address</x-label>
                            <x-input name="address" placeholder="Optional physical address" />
                        </div>

                        <div class="border-t border-gray-100 pt-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-3">Initial Admin User</p>

                            <div class="space-y-4">
                                <div>
                                    <x-label :required="true">Admin Name</x-label>
                                    <x-input name="admin_name" placeholder="Full name" required />
                                </div>

                                <div>
                                    <x-label :required="true">Email</x-label>
                                    <x-input type="email" name="email" placeholder="email@example.com" required />
                                </div>

                                <div>
                                    <x-label :required="true">Phone</x-label>
                                    <x-input name="phone" placeholder="0771234567" required />
                                </div>

                                <div x-data="{ show: false }">
                                    <x-label :required="true">Password</x-label>
                                    <div class="relative">
                                        <input :type="show ? 'text' : 'password'" name="password" required
                                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 pr-10 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                                            placeholder="8+ characters, with a letter, number &amp; symbol" />
                                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-orange-500 transition focus:outline-none">
                                            <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                            <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                                        </button>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">The admin will be required to change this on first login.</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Landlord</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
        @endstaffcan

        @staffcan('landlords.edit')
        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Landlord" maxWidth="lg">
                    <form :action="'{{ url('staff/landlords') }}/' + editing.id" method="POST" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div>
                            <x-label :required="true">Company Name</x-label>
                            <x-input name="company_name" x-model="editing.company_name" required />
                        </div>

                        <div>
                            <x-label>Trading Name</x-label>
                            <x-input name="trading_name" x-model="editing.trading_name" />
                        </div>

                        <div>
                            <x-label>Address</x-label>
                            <x-input name="address" x-model="editing.address" />
                        </div>

                        <div>
                            <x-label :required="true">Contact Email</x-label>
                            <x-input type="email" name="email" x-model="editing.email" required />
                        </div>

                        <div>
                            <x-label :required="true">Contact Phone</x-label>
                            <x-input name="phone" x-model="editing.phone" required />
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Landlord</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
        @endstaffcan
    </div>
</x-staff-layout>
