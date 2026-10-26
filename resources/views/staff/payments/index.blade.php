<x-staff-layout>
    <x-slot name="header">Payments</x-slot>

    @php
        $statusColors = ['succeeded' => 'green', 'pending' => 'orange', 'indeterminate' => 'orange', 'failed' => 'red'];
    @endphp

    <div class="max-w-7xl mx-auto">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between mb-6">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Payments</h3>
                <p class="mt-1 text-sm text-gray-500">Mobile money collected into the Primora pool account: subscriptions now, tenant payments later.</p>
            </div>
            @if($stats['unmatched'] > 0)
                <a href="{{ route('staff.payments.unmatched') }}" class="inline-flex items-center gap-2 text-sm font-medium text-orange-600 hover:text-orange-700">
                    <x-badge color="orange">{{ $stats['unmatched'] }}</x-badge> unmatched gateway callbacks &rarr;
                </a>
            @endif
        </div>

        {{-- Summary --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Collected today</p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><span class="text-sm font-semibold text-gray-500">UGX</span> {{ number_format($stats['today']) }}</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Collected this month</p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><span class="text-sm font-semibold text-gray-500">UGX</span> {{ number_format($stats['month']) }}</p>
            </div>
            <a href="{{ route('staff.payments.index', ['status' => 'pending']) }}" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 hover:border-orange-300 transition">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Awaiting outcome</p>
                <p class="mt-2 text-2xl font-bold {{ $stats['open'] ? 'text-orange-600' : 'text-gray-900' }}">{{ $stats['open'] }}</p>
                <p class="mt-1 text-xs text-gray-500">pending or indeterminate</p>
            </a>
            <a href="{{ route('staff.payments.index', ['status' => 'failed']) }}" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 hover:border-red-300 transition">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Failed (30 days)</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $stats['failed_30d'] }}</p>
            </a>
        </div>

        {{-- Filters --}}
        <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-4">
            <form method="GET" action="{{ route('staff.payments.index') }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[220px]">
                    <x-label>Search</x-label>
                    <x-input name="search" value="{{ request('search') }}" placeholder="Reference, phone, landlord, network ref, receipt…" />
                </div>
                <div class="w-40">
                    <x-label>Status</x-label>
                    <x-select name="status">
                        <option value="">All</option>
                        @foreach(['pending', 'indeterminate', 'succeeded', 'failed'] as $s)
                            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-40">
                    <x-label>Purpose</x-label>
                    <x-select name="purpose">
                        <option value="">All</option>
                        @foreach($purposes as $p)
                            <option value="{{ $p }}" @selected(request('purpose') === $p)>{{ ucfirst($p) }}</option>
                        @endforeach
                    </x-select>
                </div>
                @if($gateways->count() > 1)
                    <div class="w-32">
                        <x-label>Gateway</x-label>
                        <x-select name="gateway">
                            <option value="">All</option>
                            @foreach($gateways as $g)
                                <option value="{{ $g }}" @selected(request('gateway') === $g)>{{ $g }}</option>
                            @endforeach
                        </x-select>
                    </div>
                @endif
                <div class="w-40">
                    <x-label>From</x-label>
                    <x-input type="date" name="from" value="{{ request('from') }}" />
                </div>
                <div class="w-40">
                    <x-label>To</x-label>
                    <x-input type="date" name="to" value="{{ request('to') }}" />
                </div>
                <div class="flex gap-2">
                    <x-button type="submit">Filter</x-button>
                    @if(request()->hasAny(['search', 'status', 'purpose', 'gateway', 'from', 'to']))
                        <a href="{{ route('staff.payments.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
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
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Landlord</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Purpose</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Phone</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($payments as $payment)
                            <tr class="hover:bg-gray-50 transition-colors cursor-pointer" onclick="window.location='{{ route('staff.payments.show', $payment) }}'">
                                <td class="px-5 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $payment->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-5 py-3 text-sm">
                                    <a href="{{ route('staff.payments.show', $payment) }}" class="font-mono font-medium text-gray-900 hover:text-orange-600">{{ $payment->reference }}</a>
                                    @if($payment->gateway === 'manual')
                                        @if($payment->receipt_number)<span class="block text-xs text-gray-400">Receipt {{ $payment->receipt_number }}</span>@endif
                                    @elseif($payment->network_reference)
                                        <span class="block text-xs text-gray-500 font-mono" title="Mobile network's reference (as in the payer's SMS)">{{ \App\Payments\Msisdn::network($payment->msisdn) ?? 'Network' }} {{ $payment->network_reference }}</span>
                                    @elseif($payment->isOpen())
                                        <span class="block text-xs text-gray-400">Network ref pending</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-900">{{ $payment->landlord?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600">{{ ucfirst($payment->purpose) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-900 text-right whitespace-nowrap">{{ $payment->currency }} {{ number_format((float) $payment->amount) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $payment->displayMsisdn() }}</td>
                                <td class="px-5 py-3"><x-badge :color="$statusColors[$payment->status] ?? 'gray'">{{ ucfirst($payment->status) }}</x-badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-gray-600">No payments found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="md:hidden divide-y divide-gray-100">
                @forelse($payments as $payment)
                    <a href="{{ route('staff.payments.show', $payment) }}" class="block p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-medium text-gray-900">{{ $payment->reference }}</p>
                                @if($payment->gateway !== 'manual' && $payment->network_reference)
                                    <p class="text-xs text-gray-500 font-mono">{{ \App\Payments\Msisdn::network($payment->msisdn) ?? 'Network' }} {{ $payment->network_reference }}</p>
                                @endif
                                <p class="mt-0.5 text-sm text-gray-600">{{ $payment->landlord?->name ?? '—' }} · {{ ucfirst($payment->purpose) }}</p>
                                <p class="text-xs text-gray-500">{{ $payment->created_at->format('d M Y, H:i') }} · {{ $payment->displayMsisdn() }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-sm font-semibold text-gray-900">{{ number_format((float) $payment->amount) }}</p>
                                <x-badge :color="$statusColors[$payment->status] ?? 'gray'">{{ ucfirst($payment->status) }}</x-badge>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center text-sm text-gray-600">No payments found.</div>
                @endforelse
            </div>

            @if($payments->hasPages())
                <div class="px-4 md:px-6 py-4 border-t border-gray-200">{{ $payments->links() }}</div>
            @endif
        </div>
    </div>
</x-staff-layout>
