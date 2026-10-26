<x-staff-layout>
    <x-slot name="header">Subscriptions</x-slot>

    @php
        $states = \App\Services\SubscriptionBilling::STATES;
        $filters = ['' => 'All', 'active' => 'Active', 'expiring' => 'Expiring soon', 'trial' => 'Free trial', 'expired' => 'Expired / past due', 'cancelled' => 'Cancelled'];
    @endphp

    <div class="max-w-7xl mx-auto">
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-gray-900">Subscriptions</h3>
            <p class="mt-1 text-sm text-gray-500">Every landlord's Primora subscription. Soonest renewals are listed first.</p>
        </div>

        {{-- Summary (cards also act as filters) --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            <a href="{{ route('staff.subscriptions.index', ['state' => 'active']) }}" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 hover:border-green-300 transition">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Active</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $stats['active'] }}</p>
            </a>
            <a href="{{ route('staff.subscriptions.index', ['state' => 'trial']) }}" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 hover:border-blue-300 transition">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">On free trial</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $stats['trial'] }}</p>
            </a>
            <a href="{{ route('staff.subscriptions.index', ['state' => 'expiring']) }}" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 hover:border-orange-300 transition">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Expiring in {{ \App\Models\Landlord::EXPIRING_WITHIN_DAYS }} days</p>
                <p class="mt-2 text-2xl font-bold {{ $stats['expiring'] ? 'text-orange-600' : 'text-gray-900' }}">{{ $stats['expiring'] }}</p>
            </a>
            <a href="{{ route('staff.subscriptions.index', ['state' => 'expired']) }}" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 hover:border-red-300 transition">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Expired / past due</p>
                <p class="mt-2 text-2xl font-bold {{ $stats['expired'] ? 'text-red-600' : 'text-gray-900' }}">{{ $stats['expired'] }}</p>
            </a>
            <div class="col-span-2 lg:col-span-1 bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Monthly recurring revenue</p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><span class="text-sm font-semibold text-gray-500">UGX</span> {{ number_format($stats['mrr']) }}</p>
                <p class="mt-1 text-xs text-gray-500">UGX {{ number_format($stats['collected_month']) }} collected this month</p>
            </div>
        </div>

        {{-- Filters --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-4">
            <form method="GET" action="{{ route('staff.subscriptions.index') }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[220px]">
                    <x-label>Search landlord</x-label>
                    <x-input name="search" value="{{ request('search') }}" placeholder="Name, email or phone…" />
                </div>
                <div class="w-48">
                    <x-label>Status</x-label>
                    <x-select name="state">
                        @foreach($filters as $value => $label)
                            <option value="{{ $value }}" @selected(request('state', '') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-48">
                    <x-label>Plan</x-label>
                    <x-select name="plan">
                        <option value="">All plans</option>
                        <option value="none" @selected(request('plan') === 'none')>No plan yet</option>
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}" @selected((string) request('plan') === (string) $plan->id)>{{ $plan->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit">Filter</x-button>
                    @if(request()->hasAny(['search', 'state', 'plan']))
                        <a href="{{ route('staff.subscriptions.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        {{-- List --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Landlord</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Plan</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Paid until</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Units</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Last payment</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Total paid</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($landlords as $landlord)
                            @php
                                [$stateLabel, $stateColor] = $states[$landlord->subscriptionState()];
                                $end = $landlord->current_period_end;
                            @endphp
                            <tr class="hover:bg-gray-50 transition-colors cursor-pointer" onclick="window.location='{{ route('staff.subscriptions.show', $landlord) }}'">
                                <td class="px-5 py-3 text-sm">
                                    <a href="{{ route('staff.subscriptions.show', $landlord) }}" class="font-medium text-gray-900 hover:text-orange-600">{{ $landlord->name }}</a>
                                    @if($landlord->status !== 'active')<x-badge color="red" class="ml-1">Account {{ $landlord->status }}</x-badge>@endif
                                    <span class="block text-xs text-gray-400">{{ $landlord->contact_email }}</span>
                                </td>
                                <td class="px-5 py-3"><x-badge :color="$stateColor">{{ $stateLabel }}</x-badge></td>
                                <td class="px-5 py-3 text-sm text-gray-700">
                                    {{ $landlord->plan?->name ?? '—' }}
                                    @if($landlord->billing_cycle)<span class="block text-xs text-gray-400">{{ \App\Services\SubscriptionBilling::cycleLabel($landlord->billing_cycle) }}</span>@endif
                                </td>
                                <td class="px-5 py-3 text-sm whitespace-nowrap">
                                    @if($end)
                                        <span class="{{ $end->lt(today()) ? 'text-red-600' : 'text-gray-900' }}">{{ $end->format('d M Y') }}</span>
                                        <span class="block text-xs text-gray-400">
                                            @if($end->lt(today())) {{ (int) $end->diffInDays(today()) }} days ago @elseif($end->isToday()) today @else in {{ (int) today()->diffInDays($end) }} days @endif
                                        </span>
                                    @else
                                        <span class="text-gray-400">No end date</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-700 text-right">{{ $landlord->units_count }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $landlord->last_paid_at ? \Carbon\Carbon::parse($landlord->last_paid_at)->format('d M Y') : '—' }}</td>
                                <td class="px-5 py-3 text-sm text-gray-900 text-right whitespace-nowrap">{{ $landlord->subscription_paid ? 'UGX '.number_format((float) $landlord->subscription_paid) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-gray-600">No subscriptions match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="md:hidden divide-y divide-gray-100">
                @forelse($landlords as $landlord)
                    @php [$stateLabel, $stateColor] = $states[$landlord->subscriptionState()]; @endphp
                    <a href="{{ route('staff.subscriptions.show', $landlord) }}" class="block p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ $landlord->name }}</p>
                                <p class="mt-0.5 text-sm text-gray-600">{{ $landlord->plan?->name ?? 'No plan' }} · {{ $landlord->units_count }} units</p>
                                <p class="text-xs text-gray-500">{{ $landlord->current_period_end ? 'Paid until '.$landlord->current_period_end->format('d M Y') : 'No end date' }}</p>
                            </div>
                            <x-badge :color="$stateColor">{{ $stateLabel }}</x-badge>
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center text-sm text-gray-600">No subscriptions match.</div>
                @endforelse
            </div>

            @if($landlords->hasPages())
                <div class="px-4 md:px-6 py-4 border-t border-gray-200">{{ $landlords->links() }}</div>
            @endif
        </div>

        <p class="mt-3 text-xs text-gray-400">Monthly recurring revenue: each active subscription's plan price at the landlord's current number of units, as a monthly figure (quarterly ÷ 3, annual ÷ 12).</p>
    </div>
</x-staff-layout>
