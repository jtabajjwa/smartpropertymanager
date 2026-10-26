<x-staff-layout>
    <x-slot name="header">Revenue</x-slot>

    @php
        $streams = \App\Http\Controllers\Staff\RevenueController::STREAMS;
        // Chart colours (validated for colour-blind separation and contrast).
        $colors = ['subscription' => '#3b6ea5', 'sms' => '#c1704a'];
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $change = function (float $now, float $before) {
            if ($before == 0.0) return $now > 0 ? ['new', 'text-green-700'] : null;
            $pct = ($now - $before) / $before * 100;
            return [($pct >= 0 ? '▲ ' : '▼ ').number_format(abs($pct), 0).'%', $pct >= 0 ? 'text-green-700' : 'text-red-600'];
        };
        $smsRevenue = $current['streams']['sms']['amount'];
        $smsMargin = $smsCost !== null ? $smsRevenue - $smsCost : null;
        $maxMonth = max(1, collect($months)->max(fn ($m) => $m['subscription'] + $m['sms']));
        $yearTotal = collect($months)->sum(fn ($m) => $m['subscription'] + $m['sms']);
    @endphp

    <div class="max-w-7xl mx-auto space-y-6">
        {{-- Header + period --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Revenue</h3>
                <p class="mt-1 text-sm text-gray-500">Money received from subscriptions and SMS sales, {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}.</p>
            </div>
            <form method="GET" action="{{ route('staff.revenue.index') }}" class="flex flex-wrap items-end gap-3" x-data="{ period: '{{ $period }}' }">
                <div class="w-44">
                    <x-label>Period</x-label>
                    <x-select name="period" x-model="period" @change="if (period !== 'custom') $el.form.submit()">
                        @foreach(\App\Http\Controllers\Staff\RevenueController::PERIODS as $value => $label)
                            <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <template x-if="period === 'custom'">
                    <div class="flex items-end gap-3">
                        <div class="w-40"><x-label>From</x-label><x-input type="date" name="from" value="{{ $from->toDateString() }}" /></div>
                        <div class="w-40"><x-label>To</x-label><x-input type="date" name="to" value="{{ $to->toDateString() }}" /></div>
                        <x-button type="submit">Apply</x-button>
                    </div>
                </template>
            </form>
        </div>

        {{-- Headline figures --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            @php $c = $change($current['total'], $previous['total']); @endphp
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Total collected</p>
                <p class="mt-2 text-3xl font-bold text-gray-900">{{ $ugx($current['total']) }}</p>
                <p class="mt-1 text-xs text-gray-500">
                    {{ number_format($current['count']) }} {{ Str::plural('payment', $current['count']) }}
                    @if($c)<span class="{{ $c[1] }} font-medium" title="vs {{ $prevLabel }}"> · {{ $c[0] }} vs previous period</span>@endif
                </p>
            </div>
            @foreach($streams as $key => $label)
                @php $s = $current['streams'][$key]; $c = $change($s['amount'], $previous['streams'][$key]['amount']); @endphp
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                    <p class="flex items-center gap-2 text-xs font-medium text-gray-600 uppercase tracking-wide">
                        <span class="inline-block w-2.5 h-2.5 rounded-sm" style="background: {{ $colors[$key] }}"></span>{{ $label }}
                    </p>
                    <p class="mt-2 text-3xl font-bold text-gray-900">{{ $ugx($s['amount']) }}</p>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ $current['total'] > 0 ? number_format($s['amount'] / $current['total'] * 100, 0).'% of total · ' : '' }}{{ number_format($s['count']) }} {{ Str::plural('payment', $s['count']) }}
                        @if($c)<span class="{{ $c[1] }} font-medium" title="vs {{ $prevLabel }}"> · {{ $c[0] }}</span>@endif
                    </p>
                </div>
            @endforeach
        </div>

        {{-- SMS economics --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-base font-semibold text-gray-900">SMS sales: cost and margin</h3>
                <p class="mt-0.5 text-sm text-gray-500">Cost is the SMS landlords actually sent in this period, at the average provider cost per SMS.</p>
            </div>
            <dl class="grid grid-cols-2 lg:grid-cols-5 divide-y lg:divide-y-0 lg:divide-x divide-gray-100">
                <div class="p-5"><dt class="text-xs text-gray-500 uppercase tracking-wide">SMS sales</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ $ugx($smsRevenue) }}</dd><dd class="text-xs text-gray-500">{{ number_format($smsSold) }} SMS sold</dd></div>
                <div class="p-5"><dt class="text-xs text-gray-500 uppercase tracking-wide">SMS sent</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ number_format($smsSent) }}</dd><dd class="text-xs text-gray-500">incl. free starter SMS</dd></div>
                <div class="p-5"><dt class="text-xs text-gray-500 uppercase tracking-wide">Cost of SMS sent</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ $smsCost === null ? '—' : $ugx($smsCost) }}</dd><dd class="text-xs text-gray-500">{{ $avgCost === null ? 'No provider purchases recorded' : 'at UGX '.rtrim(rtrim(number_format($avgCost, 2), '0'), '.').' / SMS' }}</dd></div>
                <div class="p-5"><dt class="text-xs text-gray-500 uppercase tracking-wide">Gross margin</dt><dd class="mt-1 text-lg font-semibold {{ $smsMargin !== null && $smsMargin < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $smsMargin === null ? '—' : $ugx($smsMargin) }}</dd><dd class="text-xs text-gray-500">{{ $smsMargin !== null && $smsRevenue > 0 ? number_format($smsMargin / $smsRevenue * 100, 0).'% of SMS sales' : 'sales less cost of SMS sent' }}</dd></div>
                <div class="p-5"><dt class="text-xs text-gray-500 uppercase tracking-wide">Paid to providers</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ $ugx($providerSpend) }}</dd><dd class="text-xs text-gray-500">stock bought in this period</dd></div>
            </dl>
        </div>

        {{-- 12 months --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200" x-data="{ hover: null, view: 'chart' }">
            <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Last 12 months</h3>
                    <p class="mt-0.5 text-sm text-gray-500">{{ $months[0]['label'] }} – {{ end($months)['label'] }} · {{ $ugx($yearTotal) }} in total</p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-4 text-xs text-gray-600">
                        @foreach($streams as $key => $label)
                            <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm" style="background: {{ $colors[$key] }}"></span>{{ $label }}</span>
                        @endforeach
                    </div>
                    <div class="inline-flex gap-1 p-1 rounded-lg bg-gray-100 text-xs">
                        <button type="button" @click="view = 'chart'" class="px-2.5 py-1 rounded-md font-medium" :class="view === 'chart' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500'">Chart</button>
                        <button type="button" @click="view = 'table'" class="px-2.5 py-1 rounded-md font-medium" :class="view === 'table' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500'">Table</button>
                    </div>
                </div>
            </div>

            <div x-show="view === 'chart'" class="px-6 pt-6 pb-4">
                <div class="relative">
                    {{-- Gridlines --}}
                    <div class="absolute inset-x-0 top-0 h-56 flex flex-col justify-between pointer-events-none">
                        @foreach([1, 0.5, 0] as $f)
                            <div class="border-t {{ $f === 0 ? 'border-gray-300' : 'border-gray-100' }} relative">
                                <span class="absolute -top-2.5 left-0 bg-white pr-1 text-[10px] text-gray-400">{{ $f ? number_format($maxMonth * $f / 1000).'k' : '0' }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="relative h-56 pl-10 flex items-end gap-1 sm:gap-2">
                        @foreach($months as $i => $m)
                            @php $total = $m['subscription'] + $m['sms']; @endphp
                            <div class="relative flex-1 h-full flex flex-col justify-end items-center cursor-default" @mouseenter="hover = {{ $i }}" @mouseleave="hover = null">
                                <div class="w-full max-w-10 flex flex-col justify-end gap-0.5 transition-opacity" :class="hover !== null && hover !== {{ $i }} ? 'opacity-50' : ''" style="height: {{ $total / $maxMonth * 100 }}%">
                                    @if($m['sms'] > 0)<div class="w-full rounded-t-[4px]" style="background: {{ $colors['sms'] }}; height: {{ $m['sms'] / max($total, 1) * 100 }}%; min-height: 2px"></div>@endif
                                    @if($m['subscription'] > 0)<div class="w-full {{ $m['sms'] > 0 ? '' : 'rounded-t-[4px]' }}" style="background: {{ $colors['subscription'] }}; height: {{ $m['subscription'] / max($total, 1) * 100 }}%; min-height: 2px"></div>@endif
                                </div>
                                {{-- Tooltip --}}
                                <div x-show="hover === {{ $i }}" x-cloak class="absolute bottom-full mb-2 z-10 w-48 rounded-xl bg-white border border-gray-200 shadow-lg p-3 text-xs {{ $i > 8 ? 'right-0' : ($i < 3 ? 'left-0' : '') }}">
                                    <p class="font-semibold text-gray-900">{{ $m['label'] }}</p>
                                    @foreach($streams as $key => $label)
                                        <p class="mt-1 flex items-center justify-between gap-2 text-gray-600"><span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm" style="background: {{ $colors[$key] }}"></span>{{ $label }}</span><span class="text-gray-900">{{ number_format($m[$key]) }}</span></p>
                                    @endforeach
                                    <p class="mt-1 pt-1 border-t border-gray-100 flex justify-between font-medium text-gray-900"><span>Total</span><span>{{ $ugx($total) }}</span></p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="pl-10 mt-2 flex gap-1 sm:gap-2">
                        @foreach($months as $i => $m)
                            <div class="flex-1 text-center text-[10px] sm:text-xs text-gray-500">{{ $m['short'] }}</div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div x-show="view === 'table'" x-cloak class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Month</th>
                            @foreach($streams as $label)<th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ $label }}</th>@endforeach
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach(array_reverse($months) as $m)
                            <tr>
                                <td class="px-6 py-2.5 text-sm text-gray-700">{{ $m['label'] }}</td>
                                @foreach($streams as $key => $label)<td class="px-6 py-2.5 text-sm text-gray-900 text-right">{{ number_format($m[$key]) }}</td>@endforeach
                                <td class="px-6 py-2.5 text-sm font-medium text-gray-900 text-right">{{ number_format($m['subscription'] + $m['sms']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Channels --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-base font-semibold text-gray-900">By channel</h3></div>
                @if($channels->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-gray-500">No payments in this period.</p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach($channels as $name => $ch)
                            <li class="px-6 py-3">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-700">{{ $name }} <span class="text-xs text-gray-400">· {{ $ch['count'] }}</span></span>
                                    <span class="font-medium text-gray-900">{{ $ugx($ch['amount']) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-gray-400" style="width: {{ $current['total'] > 0 ? $ch['amount'] / $current['total'] * 100 : 0 }}%"></div></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Top landlords --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-base font-semibold text-gray-900">Top landlords</h3></div>
                @if($topLandlords->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-gray-500">No payments in this period.</p>
                @else
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Landlord</th>
                                @foreach($streams as $label)<th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ $label }}</th>@endforeach
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($topLandlords as $row)
                                <tr>
                                    <td class="px-6 py-2.5 text-sm">
                                        @staffcan('subscriptions.view')
                                            <a href="{{ route('staff.subscriptions.show', $row['landlord_id']) }}" class="text-gray-900 hover:text-orange-600">{{ $names[$row['landlord_id']] ?? '#'.$row['landlord_id'] }}</a>
                                        @else
                                            <span class="text-gray-900">{{ $names[$row['landlord_id']] ?? '#'.$row['landlord_id'] }}</span>
                                        @endstaffcan
                                    </td>
                                    @foreach($streams as $key => $label)<td class="px-4 py-2.5 text-sm text-gray-600 text-right">{{ number_format($row['by'][$key] ?? 0) }}</td>@endforeach
                                    <td class="px-6 py-2.5 text-sm font-medium text-gray-900 text-right">{{ number_format($row['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- Recent --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-base font-semibold text-gray-900">Latest collections in this period</h3></div>
            @if($recent->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">No payments in this period.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Received</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Landlord</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Stream</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Channel</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($recent as $p)
                                <tr>
                                    <td class="px-6 py-2.5 text-sm text-gray-600 whitespace-nowrap">{{ $p->completed_at->format('d M Y, H:i') }}</td>
                                    <td class="px-6 py-2.5 text-sm text-gray-900">{{ $p->landlord?->name ?? '—' }}</td>
                                    <td class="px-6 py-2.5 text-sm text-gray-700"><span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-sm" style="background: {{ $colors[$p->purpose] ?? '#9ca3af' }}"></span>{{ $streams[$p->purpose] ?? ucfirst($p->purpose) }}</span></td>
                                    <td class="px-6 py-2.5 text-sm text-gray-600">{{ $p->gateway === 'manual' ? (\App\Payments\PaymentService::OFFLINE_METHODS[$p->context['method'] ?? ''] ?? 'Offline') : 'Mobile money' }}</td>
                                    <td class="px-6 py-2.5 text-sm text-gray-900 text-right whitespace-nowrap">{{ $ugx($p->amount) }}</td>
                                    <td class="px-6 py-2.5 text-xs font-mono">
                                        @staffcan('payments.view')
                                            <a href="{{ route('staff.payments.show', $p) }}" class="text-orange-600 hover:text-orange-700">{{ $p->reference }}</a>
                                        @else
                                            <span class="text-gray-500">{{ $p->reference }}</span>
                                        @endstaffcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-staff-layout>
