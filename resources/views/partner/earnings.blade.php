<x-partner-layout title="Earnings" subtitle="Your share of your landlords' subscriptions">
    @php $ugx = fn ($v) => 'UGX '.number_format((float) $v); @endphp
    <div class="space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-navy-800 text-white rounded-2xl p-4">
                <p class="text-xs uppercase tracking-wide text-gray-300">Available</p>
                <p class="mt-1 text-2xl font-bold">{{ $ugx($balances['available']) }}</p>
                <p class="text-xs text-gray-400">{{ $balances['available'] >= $minPayout ? 'Ready to withdraw' : 'Withdraw from '.$ugx($minPayout) }}</p>
            </div>
            @foreach([['On hold', $balances['on_hold'], 'Available '.$holdingDays.' days after it\'s earned'], ['This month', $thisMonth, now()->format('F')], ['Paid out', $balances['paid'], 'All time']] as [$label, $value, $hint])
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4">
                    <p class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ $ugx($value) }}</p>
                    <p class="text-xs text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm text-gray-600 flex flex-wrap items-center justify-between gap-3">
            <p>
                @if($plan)<strong class="text-gray-900">Your terms:</strong> {{ $plan->summary() }}, on {{ mb_strtolower(\App\Models\CommissionPlan::BASES[$plan->base] ?? '') }}.@else Primora hasn't set your commission terms yet.@endif
                @if(\App\Services\PartnerCommissions::whtPercent() > 0) Withholding tax of {{ \App\Services\PartnerCommissions::whtPercent() }}% is taken off.@endif
            </p>
            @if($months->isNotEmpty())
                <form method="GET" action="{{ route('partner.earnings.statement') }}" hx-boost="false" class="flex items-center gap-2">
                    <x-select name="month">@foreach($months as $m)<option value="{{ $m }}">{{ \Carbon\Carbon::createFromFormat('Y-m', $m)->format('F Y') }}</option>@endforeach</x-select>
                    <x-button type="submit">Statement (PDF)</x-button>
                </form>
            @endif
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            @if($lines->isEmpty())
                <p class="px-6 py-12 text-center text-sm text-gray-500">Nothing yet. You earn when landlords you brought in pay for their subscription.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-600">
                            <tr>
                                <th class="px-5 py-3 text-left font-semibold">Date</th>
                                <th class="px-5 py-3 text-left font-semibold">What</th>
                                <th class="px-5 py-3 text-right font-semibold">Earned</th>
                                <th class="px-5 py-3 text-right font-semibold">Tax</th>
                                <th class="px-5 py-3 text-right font-semibold">You get</th>
                                <th class="px-5 py-3 text-left font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($lines as $l)
                                @php [$sl, $sc] = $l->stateLabel(); @endphp
                                <tr>
                                    <td class="px-5 py-3 whitespace-nowrap text-gray-600">{{ $l->earned_at->format('d M Y') }}</td>
                                    <td class="px-5 py-3">
                                        <span class="text-gray-900">{{ $l->typeLabel() }}</span>
                                        <span class="block text-xs text-gray-500">{{ $l->description }}@if($l->rate_percent) · {{ rtrim(rtrim(number_format($l->rate_percent, 2), '0'), '.') }}% of {{ number_format($l->base_amount) }}@endif</span>
                                    </td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap">{{ number_format($l->gross) }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap text-gray-500">{{ $l->wht ? number_format($l->wht) : '' }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap font-semibold {{ $l->net < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($l->net) }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap"><x-badge :color="$sc">{{ $sl }}</x-badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($lines->hasPages())<div>{{ $lines->links() }}</div>@endif
        <p class="text-xs text-gray-500">Withdrawing your earnings to mobile money or a bank account is coming next.</p>
    </div>
</x-partner-layout>
