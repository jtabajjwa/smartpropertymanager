<x-staff-layout>
    <x-slot name="header">{{ $report->title }}</x-slot>

    @php
        $n = fn ($v) => abs((float) $v) < 0.005 ? '–' : (($v < 0 ? '(' : '').number_format(abs((float) $v)).($v < 0 ? ')' : ''));
        $cols = count($report->columns);
        $span = $cols + ($report->showCodes ? 2 : 1);
        $query = request()->except(['format', 'page']);
        // Ledger links cover the same period as the report.
        $ledgerQuery = isset($from) ? ['period' => 'custom', 'from' => $from->toDateString(), 'to' => $to->toDateString()] : ['period' => 'custom', 'to' => ($asAt ?? now())->toDateString()];
    @endphp

    <div class="max-w-7xl mx-auto space-y-6">
        @include('staff.books._nav')

        {{-- Filters + exports --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            @if($report->slug === 'profit-and-loss')
                @include('staff.books._period')
            @else
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <div class="w-44">
                        <x-label>As at</x-label>
                        <x-input type="date" name="as_at" value="{{ $asAt->toDateString() }}" onchange="this.form.requestSubmit()" />
                    </div>
                    @if($report->slug === 'balance-sheet')
                        <div class="w-44">
                            <x-label>Compare with</x-label>
                            <x-input type="date" name="compare" value="{{ $compareAt->toDateString() }}" onchange="this.form.requestSubmit()" />
                        </div>
                    @endif
                    <div class="flex flex-wrap gap-1.5 pb-0.5">
                        @foreach(['Today' => now(), 'End of last month' => now()->subMonthNoOverflow()->endOfMonth(), 'End of last year' => now()->subYear()->endOfYear()] as $label => $date)
                            <a href="{{ request()->url().'?'.http_build_query(['as_at' => $date->toDateString()]) }}" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium transition {{ $asAt->isSameDay($date) ? 'border-navy-800 bg-navy-800 text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-orange-400' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </form>
            @endif

            <div class="flex gap-2">
                <a hx-boost="false" target="_blank" href="{{ request()->url().'?'.http_build_query($query + ['format' => 'pdf']) }}" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 transition">
                    <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25M9 15h6m-6 3h3M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                    PDF
                </a>
                <a hx-boost="false" href="{{ request()->url().'?'.http_build_query($query + ['format' => 'xlsx']) }}" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 transition">
                    <svg class="w-4 h-4 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5"/></svg>
                    Excel
                </a>
            </div>
        </div>

        {{-- The statement --}}
        <section class="max-w-4xl mx-auto bg-white rounded-2xl border border-gray-200 shadow-sm">
            <div class="px-6 py-5 border-b border-gray-100 text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ config('app.name') }}</p>
                <h2 class="mt-1 text-lg font-bold text-gray-900">{{ $report->title }}</h2>
                <p class="text-sm text-gray-500">{{ $report->period }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-100">
                            @if($report->showCodes)<th class="pl-6 pr-2 pt-4 pb-2 text-left font-semibold w-16">Code</th>@endif
                            <th class="px-3 pt-4 pb-2 text-left font-semibold">Account</th>
                            @foreach($report->columns as $heading)<th class="px-6 pt-4 pb-2 text-right font-semibold w-44">{{ $heading }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report->sections as $section)
                            @if($section['title'])
                                <tr><td colspan="{{ $span }}" class="px-6 pt-5 pb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $section['title'] }}</td></tr>
                            @endif
                            @foreach($section['rows'] as $line)
                                <tr class="hover:bg-gray-50/60">
                                    @if($report->showCodes)<td class="pl-6 pr-2 py-1.5 font-mono text-gray-400">{{ $line['code'] }}</td>@endif
                                    <td class="px-3 py-1.5">
                                        @if($line['account_id'])
                                            <a href="{{ route('staff.books.ledger', [$line['account_id']] + $ledgerQuery) }}" class="text-gray-700 hover:text-orange-600">{{ $line['name'] }}</a>
                                        @else
                                            <span class="text-gray-700">{{ $line['name'] }}</span>
                                        @endif
                                        @if(!empty($line['note']))<span class="block text-xs text-gray-400">{{ $line['note'] }}</span>@endif
                                    </td>
                                    @foreach($line['values'] as $i => $v)
                                        <td class="px-6 py-1.5 text-right whitespace-nowrap {{ $i > 0 && $report->slug !== 'trial-balance' ? 'text-gray-400' : 'text-gray-900' }}">{{ $n($v) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            @if($section['total_label'])
                                <tr class="border-t border-gray-300 font-semibold">
                                    @if($report->showCodes)<td></td>@endif
                                    <td class="px-3 py-2">{{ $section['total_label'] }}</td>
                                    @foreach($report->columns as $i => $_)
                                        <td class="px-6 py-2 text-right whitespace-nowrap {{ $i > 0 && $report->slug !== 'trial-balance' ? 'text-gray-400' : '' }}">{{ $n($report->sectionTotal($section['key'], $i)) }}</td>
                                    @endforeach
                                </tr>
                            @endif
                        @endforeach
                        @foreach($report->totals as $total)
                            <tr class="{{ $total['grand'] ? 'border-t-2 border-gray-900 text-base' : 'border-t border-gray-200' }} font-bold bg-orange-50/50">
                                @if($report->showCodes)<td></td>@endif
                                <td class="px-3 py-3">{{ $total['label'] }}</td>
                                @foreach($report->columns as $i => $_)
                                    @php $v = $report->totalValue($total, $i); @endphp
                                    <td class="px-6 py-3 text-right whitespace-nowrap {{ $i > 0 ? 'text-gray-400' : ($report->slug === 'profit-and-loss' ? ($v < 0 ? 'text-red-600' : 'text-green-700') : 'text-gray-900') }}">{{ $n($v) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-6 py-4 space-y-1">
                @if($report->check)
                    <p class="text-sm font-semibold {{ $report->check[1] ? 'text-green-700' : 'text-red-600' }}">{{ $report->check[1] ? '✓' : '✗' }} {{ $report->check[0] }}</p>
                @endif
                @foreach($report->notes as $note)
                    <p class="text-xs text-gray-400">{{ $note }}</p>
                @endforeach
            </div>
        </section>
    </div>
</x-staff-layout>
