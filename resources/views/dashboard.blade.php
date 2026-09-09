<x-app-layout>
    <x-slot name="header">Dashboard</x-slot>

    <!-- Date Range Filter -->
    <div class="bg-gray-900 rounded-2xl border border-white/10 p-4 mb-6">
        <form method="GET" action="{{ route('dashboard') }}" class="flex flex-wrap items-end gap-4">
            <div class="w-40">
                <x-label>From</x-label>
                <x-input type="date" name="date_from" value="{{ request('date_from') }}" />
            </div>
            <div class="w-40">
                <x-label>To</x-label>
                <x-input type="date" name="date_to" value="{{ request('date_to') }}" />
            </div>
            <div class="flex gap-2">
                <x-button type="submit">Apply</x-button>
                @if(request()->hasAny(['date_from', 'date_to']))
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-400 hover:text-white transition">Clear</a>
                @endif
            </div>
            @if(request()->hasAny(['date_from', 'date_to']))
                <p class="text-xs text-gray-500 self-center">
                    Showing financial data for
                    {{ request('date_from') ? request('date_from') : 'all time' }}
                    &mdash;
                    {{ request('date_to') ? request('date_to') : 'today' }}
                </p>
            @endif
        </form>
    </div>

    <!-- Property & Tenancy Stats (not filtered) -->
    <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4 mb-6">
        <x-stat-card label="Tenants" :value="$totalTenants" color="blue">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Customers" :value="$totalCustomers" color="purple">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Active Leases" :value="$activeLeases" color="green">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Vacant Units" :value="$vacantUnits" color="orange">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg></x-slot>
            <x-slot name="sub">of {{ $totalUnits }} total</x-slot>
        </x-stat-card>

        <x-stat-card label="Occupancy" :value="$occupancyRate . '%'" color="green">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg></x-slot>
        </x-stat-card>

    </div>

    <!-- Financial Stats (date-filtered where applicable) -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-8">
        <x-stat-card label="Pending Invoices" :value="'UGX ' . number_format($pendingInvoiceAmount, 0)" color="orange">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></x-slot>
            <x-slot name="sub">{{ $pendingInvoices }} invoices</x-slot>
        </x-stat-card>

        <x-stat-card label="Overdue" :value="'UGX ' . number_format($overdueAmount, 0)" color="red">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg></x-slot>
            <x-slot name="sub">{{ $overdueInvoices }} invoices</x-slot>
        </x-stat-card>

        <x-stat-card label="Total Cash Receipts" :value="'UGX ' . number_format($totalRawPayments, 0)" color="blue">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Incomes" :value="'UGX ' . number_format($totalPaymentsCollected, 0)" color="green">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Total Expenses" :value="'UGX ' . number_format($totalExpenses, 0)" color="red">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Net Incomes (After Expenses)" :value="'UGX ' . number_format($netIncome, 0)" color="green">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Deposits Held" :value="'UGX ' . number_format($securityDepositsHeld, 0)" color="blue">
            <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z"/></svg></x-slot>
        </x-stat-card>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Recent Payments -->
        <div class="bg-gray-900 rounded-2xl shadow-sm border border-white/10 overflow-hidden">
            <div class="px-5 py-4 border-b border-white/10 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-white">Recent Payments</h3>
                <a href="{{ route('payments.index') }}" class="text-xs font-medium text-orange-500 hover:text-orange-600">View all</a>
            </div>
            <div class="divide-y divide-white/5">
                @forelse ($recentPayments as $payment)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-white">
                                {{ $payment->display_payer_name }}
                                @if($payment->payable_type === \App\Models\Customer::class)
                                    <span class="text-xs text-gray-500 font-normal ml-1">(Casual Customer)</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-400">{{ $payment->payment_date->format('M d, Y') }} &middot; {{ ucwords(str_replace('_', ' ', $payment->payment_method)) }}</p>
                        </div>
                        <p class="text-sm font-semibold text-white">UGX {{ number_format($payment->amount, 0) }}</p>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">No payments{{ request()->hasAny(['date_from', 'date_to']) ? ' in this period' : ' yet' }}</div>
                @endforelse
            </div>
        </div>

        <!-- Recent Invoices -->
        <div class="bg-gray-900 rounded-2xl shadow-sm border border-white/10 overflow-hidden">
            <div class="px-5 py-4 border-b border-white/10 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-white">Recent Invoices</h3>
                <a href="{{ route('invoices.index') }}" class="text-xs font-medium text-orange-500 hover:text-orange-600">View all</a>
            </div>
            <div class="divide-y divide-white/5">
                @forelse ($recentInvoices as $invoice)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-white">{{ $invoice->title }}</p>
                            <p class="text-xs text-gray-400">
                                {{ $invoice->display_payer_name }}
                                @if($invoice->invoiceable_type === \App\Models\Customer::class)
                                    <span class="text-gray-500">(Casual Customer)</span>
                                @endif
                                &middot; {{ $invoice->due_date->format('M d, Y') }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold text-white">UGX {{ number_format($invoice->amount, 0) }}</p>
                            <x-badge :color="match($invoice->status) { 'paid' => 'green', 'partial' => 'orange', default => 'red' }">{{ ucfirst($invoice->status) }}</x-badge>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">No invoices{{ request()->hasAny(['date_from', 'date_to']) ? ' in this period' : ' yet' }}</div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Expiring Leases Alert -->
    @if ($expiringLeases->count() > 0)
        <div class="bg-gray-900 rounded-2xl shadow-sm border border-orange-500/20 overflow-hidden">
            <div class="px-5 py-4 border-b border-orange-500/10 bg-orange-500/5">
                <h3 class="text-sm font-semibold text-orange-400 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    Leases Expiring Within 30 Days
                </h3>
            </div>
            <div class="divide-y divide-white/5">
                @foreach ($expiringLeases as $lease)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-white">{{ $lease->tenant?->name ?? 'N/A' }}</p>
                            <p class="text-xs text-gray-400">{{ $lease->unit?->name ?? 'N/A' }} &middot; {{ $lease->number }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold text-red-600">{{ $lease->end_date->format('M d, Y') }}</p>
                            <p class="text-xs text-gray-400">{{ $lease->daysRemaining() }} days left</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-app-layout>
