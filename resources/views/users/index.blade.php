<x-app-layout>
    <x-slot name="header">Users</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        editing: {
            id: null,
            name: '',
            email: '',
            phone: '',
            role: 'user',
        },
        edit(item) {
            this.editing = {
                id: item.id,
                name: item.name,
                email: item.email || '',
                phone: item.phone || '',
                role: item.role,
            };
            this.editModal = true;
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">All Users</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage system users and their access.</p>
                </div>
                @if(\App\Support\PlanFeatures::allows('team'))
                    <x-button @click="createModal = true" type="button">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add User
                    </x-button>
                @endif
            </div>

            @unless(\App\Support\PlanFeatures::allows('team'))
                <div class="mb-6 flex flex-col gap-3 rounded-2xl border border-orange-200 bg-orange-50/80 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="flex items-center gap-2 text-sm text-gray-700"><x-plan-lock /> Your plan has one user. Team members, each with their own sign-in, come with a higher plan.</p>
                    <a href="{{ route('subscription.show') }}" class="shrink-0 text-sm font-medium text-orange-600 hover:text-orange-700">See plans &rarr;</a>
                </div>
            @endunless

            {{-- Filters --}}
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-6">
                <form method="GET" action="{{ route('users.index') }}" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <x-label>Search</x-label>
                        <x-input name="search" value="{{ request('search') }}" placeholder="Name, email, phone..." />
                    </div>
                    <div class="w-36">
                        <x-label>Role</x-label>
                        <x-select name="role">
                            <option value="">All Roles</option>
                            <option value="admin" {{ request('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                            <option value="user" {{ request('role') === 'user' ? 'selected' : '' }}>User</option>
                        </x-select>
                    </div>
                    <div class="w-36">
                        <x-label>Status</x-label>
                        <x-select name="status">
                            <option value="">All Statuses</option>
                            <option value="1" {{ request('status') === '1' ? 'selected' : '' }}>Active</option>
                            <option value="0" {{ request('status') === '0' ? 'selected' : '' }}>Inactive</option>
                        </x-select>
                    </div>
                    <div class="flex gap-2">
                        <x-button type="submit">Filter</x-button>
                        @if(request()->hasAny(['search', 'role', 'status']))
                            <a href="{{ route('users.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
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
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Phone</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Role</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($users as $user)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $user->email }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $user->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $user->phone ?? '--' }}</td>
                                    <td class="px-6 py-4">
                                        <x-badge :color="$user->role === 'admin' ? 'orange' : 'blue'">{{ ucfirst($user->role) }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4">
                                        <x-badge :color="$user->is_active ? 'green' : 'red'">{{ $user->is_active ? 'Active' : 'Inactive' }}</x-badge>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="edit({{ Js::from($user) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    @if(! $user->is(auth()->user()))
                                                        <form action="{{ route('users.toggle-active', $user) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to {{ $user->is_active ? 'deactivate' : 'activate' }} this user?', $el)">
                                                            @csrf
                                                            @method('PATCH')
                                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm {{ $user->is_active ? 'text-red-600' : 'text-green-600' }} hover:bg-gray-50 transition">
                                                                {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                                            </button>
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
                                        <p class="text-sm text-gray-600">No users found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($users as $user)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 break-words">{{ $user->name }}</p>
                                    <p class="mt-0.5 text-sm text-gray-600 break-words">{{ $user->email }}</p>
                                    <p class="text-sm text-gray-600">{{ $user->phone ?? '--' }}</p>
                                </div>
                                <x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        <button @click="edit({{ Js::from($user) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                        @if(! $user->is(auth()->user()))
                                            <form action="{{ route('users.toggle-active', $user) }}" method="POST" @submit.prevent="$store.confirm.open('Are you sure you want to {{ $user->is_active ? 'deactivate' : 'activate' }} this user?', $el)">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="w-full text-left px-4 py-2 text-sm {{ $user->is_active ? 'text-red-600' : 'text-green-600' }} hover:bg-gray-50 transition">
                                                    {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                        @endif
                                    </x-slot>
                                </x-dropdown>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-badge :color="$user->role === 'admin' ? 'orange' : 'blue'">{{ ucfirst($user->role) }}</x-badge>
                                <x-badge :color="$user->is_active ? 'green' : 'red'">{{ $user->is_active ? 'Active' : 'Inactive' }}</x-badge>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No users found.</p>
                        </div>
                    @endforelse
                </div>

                @if ($users->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $users->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add User" maxWidth="lg">
                    <form action="{{ route('users.store') }}" method="POST" class="space-y-4">
                        @csrf

                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" placeholder="Full name" required />
                        </div>

                        <div>
                            <x-label :required="true">Email</x-label>
                            <x-input type="email" name="email" placeholder="email@example.com" required />
                        </div>

                        <div>
                            <x-label>Phone</x-label>
                            <x-input name="phone" placeholder="0771234567" />
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
                            <p class="mt-1 text-xs text-gray-500">User will be required to change this on first login.</p>
                        </div>

                        <div>
                            <x-label :required="true">Role</x-label>
                            <x-select name="role" required>
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </x-select>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save User</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit User" maxWidth="lg">
                    <form :action="'{{ url('users') }}/' + editing.id" method="POST" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" x-model="editing.name" required />
                        </div>

                        <div>
                            <x-label :required="true">Email</x-label>
                            <x-input type="email" name="email" x-model="editing.email" required />
                        </div>

                        <div>
                            <x-label>Phone</x-label>
                            <x-input name="phone" x-model="editing.phone" placeholder="0771234567" />
                        </div>

                        <div>
                            <x-label :required="true">Role</x-label>
                            <x-select name="role" x-model="editing.role" required>
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </x-select>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update User</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
