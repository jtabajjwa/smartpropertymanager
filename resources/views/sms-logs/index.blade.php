<x-app-layout>
    <x-slot name="header">SMS Logs</x-slot>

    <div class="max-w-7xl mx-auto space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-gray-900">SMS Logs</h1>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Total Sent</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($stats->sent) }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Failed</p>
                <p class="text-2xl font-bold text-red-600 mt-1">{{ number_format($stats->failed) }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">Today</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($stats->today) }}</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wider">SMS Credit</p>
                <p class="text-2xl font-bold {{ $balance > 0 ? 'text-orange-600' : 'text-red-600' }} mt-1">{{ number_format($balance) }} <span class="text-sm font-medium text-gray-500">SMS</span></p>
                <a href="{{ route('sms-credits.show') }}" class="text-xs font-medium text-orange-600 hover:text-orange-700">Buy SMS →</a>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <form method="GET" action="{{ route('sms-logs.index') }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[200px]">
                    <x-label>Search</x-label>
                    <x-input name="search" value="{{ request('search') }}" placeholder="Phone, message, tenant..." />
                </div>
                <div class="w-44">
                    <x-label>Type</x-label>
                    <x-select name="notification_type">
                        <option value="">All Types</option>
                        <option value="invoice_created" {{ request('notification_type') === 'invoice_created' ? 'selected' : '' }}>Invoice Created</option>
                        <option value="overdue_reminder" {{ request('notification_type') === 'overdue_reminder' ? 'selected' : '' }}>Overdue Reminder</option>
                        <option value="payment_received" {{ request('notification_type') === 'payment_received' ? 'selected' : '' }}>Payment Received</option>
                        <option value="lease_expiring" {{ request('notification_type') === 'lease_expiring' ? 'selected' : '' }}>Lease Expiring</option>
                        <option value="deposit_deduction" {{ request('notification_type') === 'deposit_deduction' ? 'selected' : '' }}>Deposit Deduction</option>
                        <option value="deposit_refund" {{ request('notification_type') === 'deposit_refund' ? 'selected' : '' }}>Deposit Refund</option>
                        <option value="upcoming_reminder" {{ request('notification_type') === 'upcoming_reminder' ? 'selected' : '' }}>Upcoming Reminder</option>
                    </x-select>
                </div>
                <div class="w-28">
                    <x-label>Status</x-label>
                    <x-select name="status">
                        <option value="">All</option>
                        <option value="sent" {{ request('status') === 'sent' ? 'selected' : '' }}>Sent</option>
                        <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                        <option value="no_credit" {{ request('status') === 'no_credit' ? 'selected' : '' }}>No credit</option>
                        <option value="read_only" {{ request('status') === 'read_only' ? 'selected' : '' }}>Not sent: read-only</option>
                    </x-select>
                </div>
                <div class="w-44">
                    <x-label>Tenant</x-label>
                    <x-search-select name="tenant_id" url="{{ route('search.tenants') }}" placeholder="All Tenants" />
                </div>
                <div class="w-36">
                    <x-label>From</x-label>
                    <x-input type="date" name="date_from" value="{{ request('date_from') }}" />
                </div>
                <div class="w-36">
                    <x-label>To</x-label>
                    <x-input type="date" name="date_to" value="{{ request('date_to') }}" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit">Filter</x-button>
                    @if(request()->hasAny(['search', 'notification_type', 'status', 'tenant_id', 'date_from', 'date_to']))
                        <a href="{{ route('sms-logs.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        <!-- Table (md and up) -->
        <div class="bg-white rounded-2xl border border-gray-200">
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">When</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Tenant</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Phone</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Type</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Message</th>
                            <th class="px-5 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    @forelse($logs as $log)
                        <tbody x-data="{ expanded: false }" class="divide-y divide-gray-100">
                            <tr @click="expanded = !expanded" class="hover:bg-gray-50 transition cursor-pointer">
                                <td class="px-5 py-3 text-gray-700 whitespace-nowrap">
                                    <span title="{{ $log->created_at->format('Y-m-d H:i:s') }}">{{ $log->created_at->diffForHumans() }}</span>
                                </td>
                                <td class="px-5 py-3 text-gray-700 whitespace-nowrap">{{ $log->tenant?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-gray-600 whitespace-nowrap font-mono text-xs">{{ $log->phone }}</td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    @switch($log->notification_type)
                                        @case('invoice_created')
                                            <x-badge color="blue">Invoice</x-badge>
                                            @break
                                        @case('overdue_reminder')
                                            <x-badge color="red">Overdue</x-badge>
                                            @break
                                        @case('payment_received')
                                            <x-badge color="green">Payment</x-badge>
                                            @break
                                        @case('lease_expiring')
                                            <x-badge color="orange">Lease Expiry</x-badge>
                                            @break
                                        @case('deposit_deduction')
                                            <x-badge color="red">Deduction</x-badge>
                                            @break
                                        @case('deposit_refund')
                                            <x-badge color="green">Refund</x-badge>
                                            @break
                                        @case('upcoming_reminder')
                                            <x-badge color="orange">Upcoming</x-badge>
                                            @break
                                        @default
                                            <x-badge>{{ $log->notification_type }}</x-badge>
                                    @endswitch
                                </td>
                                <td class="px-5 py-3 text-gray-600 max-w-xs truncate">{{ Str::limit($log->message, 60) }}</td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    @if($log->status === 'sent')
                                        <x-badge color="green">Sent</x-badge>
                                    @elseif($log->status === 'no_credit')
                                        <x-badge color="orange">No credit</x-badge>
                                        @elseif($log->status === 'read_only')
                                        <x-badge color="gray">Not sent: read-only</x-badge>
                                    @else
                                        <x-badge color="red">Failed</x-badge>
                                    @endif
                                </td>
                            </tr>
                            <tr x-show="expanded" x-cloak class="bg-gray-50">
                                <td colspan="6" class="px-5 py-4">
                                    <div class="space-y-2 max-w-2xl">
                                        <div class="flex items-start gap-3 text-xs">
                                            <span class="font-medium text-gray-600 w-24 shrink-0">Message</span>
                                            <span class="text-gray-700 whitespace-pre-wrap">{{ $log->message }}</span>
                                        </div>
                                        <div class="flex items-start gap-3 text-xs">
                                            <span class="font-medium text-gray-600 w-24 shrink-0">Sent At</span>
                                            <span class="text-gray-700">{{ $log->created_at->format('d M Y, H:i:s') }}</span>
                                        </div>
                                        @if($log->reference_type)
                                            <div class="flex items-start gap-3 text-xs">
                                                <span class="font-medium text-gray-600 w-24 shrink-0">Reference</span>
                                                <span class="text-gray-700">{{ $log->reference_type }} #{{ $log->reference_id }}</span>
                                            </div>
                                        @endif
                                        @if($log->error)
                                            <div class="flex items-start gap-3 text-xs">
                                                <span class="font-medium text-gray-600 w-24 shrink-0">Error</span>
                                                <span class="text-red-600">{{ $log->error }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @empty
                        <tbody>
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center text-gray-500">No SMS logs found.</td>
                            </tr>
                        </tbody>
                    @endforelse
                </table>
            </div>

            {{-- Cards (below md) --}}
            <div class="md:hidden divide-y divide-gray-100">
                @forelse($logs as $log)
                    <div class="p-4" x-data="{ expanded: false }">
                        <div @click="expanded = !expanded" class="cursor-pointer">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 break-words">{{ $log->tenant?->name ?? '—' }}</p>
                                    <p class="mt-0.5 text-sm text-gray-600 font-mono text-xs">{{ $log->phone }}</p>
                                    <p class="text-sm text-gray-600" title="{{ $log->created_at->format('Y-m-d H:i:s') }}">{{ $log->created_at->diffForHumans() }}</p>
                                </div>
                                <div>
                                    @if($log->status === 'sent')
                                        <x-badge color="green">Sent</x-badge>
                                    @elseif($log->status === 'no_credit')
                                        <x-badge color="orange">No credit</x-badge>
                                        @elseif($log->status === 'read_only')
                                        <x-badge color="gray">Not sent: read-only</x-badge>
                                    @else
                                        <x-badge color="red">Failed</x-badge>
                                    @endif
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @switch($log->notification_type)
                                    @case('invoice_created')
                                        <x-badge color="blue">Invoice</x-badge>
                                        @break
                                    @case('overdue_reminder')
                                        <x-badge color="red">Overdue</x-badge>
                                        @break
                                    @case('payment_received')
                                        <x-badge color="green">Payment</x-badge>
                                        @break
                                    @case('lease_expiring')
                                        <x-badge color="orange">Lease Expiry</x-badge>
                                        @break
                                    @case('deposit_deduction')
                                        <x-badge color="red">Deduction</x-badge>
                                        @break
                                    @case('deposit_refund')
                                        <x-badge color="green">Refund</x-badge>
                                        @break
                                    @case('upcoming_reminder')
                                        <x-badge color="orange">Upcoming</x-badge>
                                        @break
                                    @default
                                        <x-badge>{{ $log->notification_type }}</x-badge>
                                @endswitch
                            </div>
                            <p class="mt-3 text-sm text-gray-600">{{ Str::limit($log->message, 60) }}</p>
                        </div>
                        <div x-show="expanded" x-cloak class="mt-3 pt-3 border-t border-gray-100 space-y-2">
                            <div class="flex items-start gap-3 text-xs">
                                <span class="font-medium text-gray-600 w-20 shrink-0">Message</span>
                                <span class="text-gray-700 whitespace-pre-wrap">{{ $log->message }}</span>
                            </div>
                            <div class="flex items-start gap-3 text-xs">
                                <span class="font-medium text-gray-600 w-20 shrink-0">Sent At</span>
                                <span class="text-gray-700">{{ $log->created_at->format('d M Y, H:i:s') }}</span>
                            </div>
                            @if($log->reference_type)
                                <div class="flex items-start gap-3 text-xs">
                                    <span class="font-medium text-gray-600 w-20 shrink-0">Reference</span>
                                    <span class="text-gray-700">{{ $log->reference_type }} #{{ $log->reference_id }}</span>
                                </div>
                            @endif
                            @if($log->error)
                                <div class="flex items-start gap-3 text-xs">
                                    <span class="font-medium text-gray-600 w-20 shrink-0">Error</span>
                                    <span class="text-red-600">{{ $log->error }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center">
                        <p class="text-sm text-gray-600">No SMS logs found.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Pagination -->
        @if($logs->hasPages())
            <div class="mt-4">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
