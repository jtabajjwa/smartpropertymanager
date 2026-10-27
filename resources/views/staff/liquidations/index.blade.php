<x-staff-layout>
    <x-slot name="header">Payouts</x-slot>

    <div class="max-w-7xl mx-auto space-y-6">
        @include('staff.liquidations._nav')

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            @foreach(\App\Http\Controllers\Staff\LiquidationController::TABS as $key => [$label])
                <a href="{{ route('staff.liquidations.index', ['tab' => $key]) }}" class="rounded-2xl border p-4 shadow-sm transition {{ $tab === $key ? 'bg-navy-800 border-navy-800 text-white' : 'bg-white border-gray-200 hover:border-orange-300' }}">
                    <p class="text-xs font-medium uppercase tracking-wide {{ $tab === $key ? 'text-gray-300' : 'text-gray-500' }}">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold {{ $tab !== $key && in_array($key, ['to_approve', 'to_execute']) && $counts[$key] ? 'text-orange-600' : '' }}">{{ $counts[$key] }}</p>
                </a>
            @endforeach
        </div>

        <p class="text-sm text-gray-500">Held for landlords in total (books): <strong class="text-gray-900">UGX {{ number_format($held) }}</strong></p>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            @if($payouts->isEmpty())
                <p class="px-6 py-12 text-center text-sm text-gray-500">Nothing here.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Requested</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Landlord</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">To</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Net</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($payouts as $p)
                                <tr class="hover:bg-gray-50 cursor-pointer" onclick="if (event.target.closest('a, button, form')) return; this.querySelector('a[href]').click()">
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <a href="{{ route('staff.liquidations.show', $p->id) }}" class="font-mono text-gray-900 hover:text-orange-600">{{ $p->reference }}</a>
                                        <span class="block text-xs text-gray-400">{{ $p->created_at->format('d M Y, H:i') }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-gray-900">{{ $p->landlord?->name }}</td>
                                    <td class="px-5 py-3 text-gray-600">
                                        <span class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $p->isMobileMoney() ? 'Mobile money' : 'Bank' }}</span>
                                        <span class="block">{{ $p->destinationSummary() }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap">{{ number_format((float) $p->amount) }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap font-medium">{{ number_format((float) $p->net_amount) }}</td>
                                    <td class="px-5 py-3">
                                        <x-badge :color="$p->statusColor()">{{ $p->staffStatus() }}</x-badge>
                                        @if($p->reviewedBy)<span class="block mt-1 text-xs text-gray-400">Approved by {{ $p->reviewedBy->name }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($payouts->hasPages())
                    <div class="px-5 py-4 border-t border-gray-200">{{ $payouts->links() }}</div>
                @endif
            @endif
        </div>
    </div>
</x-staff-layout>
