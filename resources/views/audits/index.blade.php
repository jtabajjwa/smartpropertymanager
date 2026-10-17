<x-app-layout>
    <x-slot name="header">Audit Log</x-slot>

    <div class="max-w-7xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-gray-900">Audit Log</h1>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <form method="GET" action="{{ route('audits.index') }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[200px]">
                    <x-label>Search</x-label>
                    <x-input name="search" value="{{ request('search') }}" placeholder="Model, user..." />
                </div>
                <div class="w-36">
                    <x-label>Action</x-label>
                    <x-select name="action">
                        <option value="">All Actions</option>
                        <option value="created" {{ request('action') === 'created' ? 'selected' : '' }}>Created</option>
                        <option value="updated" {{ request('action') === 'updated' ? 'selected' : '' }}>Updated</option>
                        <option value="deleted" {{ request('action') === 'deleted' ? 'selected' : '' }}>Deleted</option>
                    </x-select>
                </div>
                <div class="w-48">
                    <x-label>Model Type</x-label>
                    <x-select name="auditable_type">
                        <option value="">All Models</option>
                        <option value="App\Models\Customer" {{ request('auditable_type') === 'App\Models\Customer' ? 'selected' : '' }}>Customer</option>
                        <option value="App\Models\Tenant" {{ request('auditable_type') === 'App\Models\Tenant' ? 'selected' : '' }}>Tenant</option>
                        <option value="App\Models\Lease" {{ request('auditable_type') === 'App\Models\Lease' ? 'selected' : '' }}>Lease</option>
                        <option value="App\Models\Invoice" {{ request('auditable_type') === 'App\Models\Invoice' ? 'selected' : '' }}>Invoice</option>
                        <option value="App\Models\Payment" {{ request('auditable_type') === 'App\Models\Payment' ? 'selected' : '' }}>Payment</option>
                        <option value="App\Models\User" {{ request('auditable_type') === 'App\Models\User' ? 'selected' : '' }}>User</option>
                        <option value="App\Models\PaymentAllocation" {{ request('auditable_type') === 'App\Models\PaymentAllocation' ? 'selected' : '' }}>Payment Allocation</option>
                        <option value="App\Models\Utility" {{ request('auditable_type') === 'App\Models\Utility' ? 'selected' : '' }}>Utility</option>
                        <option value="App\Models\Estate" {{ request('auditable_type') === 'App\Models\Estate' ? 'selected' : '' }}>Estate</option>
                        <option value="App\Models\Location" {{ request('auditable_type') === 'App\Models\Location' ? 'selected' : '' }}>Location</option>
                    </x-select>
                </div>
                <div class="w-44">
                    <x-label>User</x-label>
                    <x-search-select name="user_id" url="{{ route('search.users') }}" placeholder="All Users" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit">Filter</x-button>
                    @if(request()->hasAny(['search', 'action', 'auditable_type', 'user_id']))
                        <a href="{{ route('audits.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        <!-- Table (md and up) -->
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">When</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">User</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Action</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Model</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Record</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Changes</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">IP</th>
                        </tr>
                    </thead>
                    @forelse($audits as $audit)
                        @php
                            $hasDetails = ($audit->action === 'updated' && $audit->old_values && $audit->new_values)
                                || ($audit->action === 'created' && $audit->new_values)
                                || ($audit->action === 'deleted' && $audit->old_values);
                        @endphp
                        <tbody x-data="{ expanded: false }" class="divide-y divide-gray-100">
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-5 py-3 text-gray-700 whitespace-nowrap">
                                    <span title="{{ $audit->created_at->format('Y-m-d H:i:s') }}">{{ $audit->created_at->diffForHumans() }}</span>
                                </td>
                                <td class="px-5 py-3 text-gray-700 whitespace-nowrap">{{ $audit->user?->name ?? 'System' }}</td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    @if($audit->action === 'created')
                                        <x-badge color="green">Created</x-badge>
                                    @elseif($audit->action === 'updated')
                                        <x-badge color="orange">Updated</x-badge>
                                    @elseif($audit->action === 'deleted')
                                        <x-badge color="red">Deleted</x-badge>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-gray-700 whitespace-nowrap">{{ class_basename($audit->auditable_type) }}</td>
                                <td class="px-5 py-3 text-gray-700 whitespace-wrap min-w-[150px] max-w-[250px] truncate" title="{{ $audit->record_name }}">
                                    {{ $audit->record_name }} <span class="text-xs text-gray-400 ml-1">#{{ $audit->auditable_id }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    @if($hasDetails)
                                        <button @click="expanded = !expanded" class="text-orange-500 hover:text-orange-600 text-xs font-medium transition">
                                            @if($audit->action === 'updated')
                                                <span x-text="expanded ? 'Hide' : 'View {{ count($audit->new_values) }} change(s)'"></span>
                                            @elseif($audit->action === 'created')
                                                <span x-text="expanded ? 'Hide' : 'View details'"></span>
                                            @elseif($audit->action === 'deleted')
                                                <span x-text="expanded ? 'Hide' : 'View deleted data'"></span>
                                            @endif
                                        </button>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-gray-500 whitespace-nowrap text-xs">{{ $audit->ip_address }}</td>
                            </tr>
                            @if($hasDetails)
                                <tr x-show="expanded" x-cloak class="bg-gray-50">
                                    <td colspan="7" class="px-5 py-3">
                                        <div class="grid grid-cols-1 gap-2 max-w-2xl">
                                            @if($audit->action === 'updated')
                                                @foreach($audit->new_values as $field => $newVal)
                                                    <div class="flex items-start gap-3 text-xs">
                                                        <span class="font-medium text-gray-600 w-32 shrink-0">{{ str_replace('_', ' ', ucfirst($field)) }}</span>
                                                        <span class="text-red-600/70 line-through">{{ is_array($audit->old_values[$field] ?? null) ? json_encode($audit->old_values[$field]) : ($audit->old_values[$field] ?? '—') }}</span>
                                                        <svg class="w-3 h-3 text-gray-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                                        <span class="text-green-600/70">{{ is_array($newVal) ? json_encode($newVal) : $newVal }}</span>
                                                    </div>
                                                @endforeach
                                            @elseif($audit->action === 'created')
                                                @foreach($audit->new_values as $field => $val)
                                                    @if(!in_array($field, ['id', 'created_at', 'updated_at']))
                                                        <div class="flex items-start gap-3 text-xs">
                                                            <span class="font-medium text-gray-600 w-32 shrink-0">{{ str_replace('_', ' ', ucfirst($field)) }}</span>
                                                            <span class="text-green-600/70">{{ is_array($val) ? json_encode($val) : ($val ?? '—') }}</span>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            @elseif($audit->action === 'deleted')
                                                @foreach($audit->old_values as $field => $val)
                                                    @if(!in_array($field, ['id', 'created_at', 'updated_at']))
                                                        <div class="flex items-start gap-3 text-xs">
                                                            <span class="font-medium text-gray-600 w-32 shrink-0">{{ str_replace('_', ' ', ucfirst($field)) }}</span>
                                                            <span class="text-red-600/70">{{ is_array($val) ? json_encode($val) : ($val ?? '—') }}</span>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    @empty
                        <tbody>
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center text-gray-500">No audit records found.</td>
                            </tr>
                        </tbody>
                    @endforelse
                </table>
            </div>

            {{-- Cards (below md) --}}
            <div class="md:hidden divide-y divide-gray-100">
                @forelse($audits as $audit)
                    @php
                        $hasDetails = ($audit->action === 'updated' && $audit->old_values && $audit->new_values)
                            || ($audit->action === 'created' && $audit->new_values)
                            || ($audit->action === 'deleted' && $audit->old_values);
                    @endphp
                    <div class="p-4" x-data="{ expanded: false }">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 break-words" title="{{ $audit->record_name }}">
                                    {{ $audit->record_name }} <span class="text-xs text-gray-400">#{{ $audit->auditable_id }}</span>
                                </p>
                                <p class="mt-0.5 text-sm text-gray-600">{{ class_basename($audit->auditable_type) }}</p>
                                <p class="text-sm text-gray-600">{{ $audit->user?->name ?? 'System' }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if($audit->action === 'created')
                                <x-badge color="green">Created</x-badge>
                            @elseif($audit->action === 'updated')
                                <x-badge color="orange">Updated</x-badge>
                            @elseif($audit->action === 'deleted')
                                <x-badge color="red">Deleted</x-badge>
                            @endif
                        </div>
                        <div class="mt-3 text-sm text-gray-600 space-y-1">
                            <p><span class="text-gray-500">When:</span> <span title="{{ $audit->created_at->format('Y-m-d H:i:s') }}">{{ $audit->created_at->diffForHumans() }}</span></p>
                            <p><span class="text-gray-500">IP:</span> {{ $audit->ip_address }}</p>
                        </div>
                        @if($hasDetails)
                            <button @click="expanded = !expanded" class="mt-3 text-orange-500 hover:text-orange-600 text-xs font-medium transition">
                                @if($audit->action === 'updated')
                                    <span x-text="expanded ? 'Hide' : 'View {{ count($audit->new_values) }} change(s)'"></span>
                                @elseif($audit->action === 'created')
                                    <span x-text="expanded ? 'Hide' : 'View details'"></span>
                                @elseif($audit->action === 'deleted')
                                    <span x-text="expanded ? 'Hide' : 'View deleted data'"></span>
                                @endif
                            </button>
                            <div x-show="expanded" x-cloak class="mt-2 bg-gray-50 rounded-lg p-3">
                                <div class="grid grid-cols-1 gap-2">
                                    @if($audit->action === 'updated')
                                        @foreach($audit->new_values as $field => $newVal)
                                            <div class="flex items-start gap-3 text-xs">
                                                <span class="font-medium text-gray-600 w-32 shrink-0">{{ str_replace('_', ' ', ucfirst($field)) }}</span>
                                                <span class="text-red-600/70 line-through">{{ is_array($audit->old_values[$field] ?? null) ? json_encode($audit->old_values[$field]) : ($audit->old_values[$field] ?? '—') }}</span>
                                                <svg class="w-3 h-3 text-gray-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                                <span class="text-green-600/70">{{ is_array($newVal) ? json_encode($newVal) : $newVal }}</span>
                                            </div>
                                        @endforeach
                                    @elseif($audit->action === 'created')
                                        @foreach($audit->new_values as $field => $val)
                                            @if(!in_array($field, ['id', 'created_at', 'updated_at']))
                                                <div class="flex items-start gap-3 text-xs">
                                                    <span class="font-medium text-gray-600 w-32 shrink-0">{{ str_replace('_', ' ', ucfirst($field)) }}</span>
                                                    <span class="text-green-600/70">{{ is_array($val) ? json_encode($val) : ($val ?? '—') }}</span>
                                                </div>
                                            @endif
                                        @endforeach
                                    @elseif($audit->action === 'deleted')
                                        @foreach($audit->old_values as $field => $val)
                                            @if(!in_array($field, ['id', 'created_at', 'updated_at']))
                                                <div class="flex items-start gap-3 text-xs">
                                                    <span class="font-medium text-gray-600 w-32 shrink-0">{{ str_replace('_', ' ', ucfirst($field)) }}</span>
                                                    <span class="text-red-600/70">{{ is_array($val) ? json_encode($val) : ($val ?? '—') }}</span>
                                                </div>
                                            @endif
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-8 text-center">
                        <p class="text-sm text-gray-600">No audit records found.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $audits->links() }}
        </div>
    </div>
</x-app-layout>
