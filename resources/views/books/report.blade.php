<x-app-layout>
    <x-slot name="header">{{ $report->title }}</x-slot>

    @php
        $n = fn ($v) => abs((float) $v) < 0.005 ? '–' : (($v < 0 ? '(' : '').number_format(abs((float) $v)).($v < 0 ? ')' : ''));
        $last = count($report->columns) - 1;
        $span = count($report->columns) + ($report->showCodes ? 2 : 1);
        $asAtMode = isset($asAt);
        // Account links cover the report's period: the fiscal year to date for "as at" reports.
        if ($asAtMode) {
            $linkQuery = ['from' => $books->fiscalYearRange($books->fiscalYearOf($asAt))[0]->toDateString(), 'to' => $asAt->toDateString()];
        } else {
            $linkQuery = $year ? ['year' => $year] : ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }
        $colour = fn ($v) => in_array($report->slug, ['income-statement', 'by-property'], true) ? ($v < 0 ? 'text-red-600' : 'text-green-700') : 'text-gray-900';
    @endphp

    <div class="max-w-6xl mx-auto space-y-6">
        @include('books._nav')

        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            @if($asAtMode)
                @php
                    $fy = $books->fiscalYearOf();
                    $quick = ['Today' => now(), 'End of last fiscal year' => $books->fiscalYearRange($fy)[0]->subDay(), 'End of last month' => now()->subMonthNoOverflow()->endOfMonth()];
                @endphp
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <div class="w-44">
                        <x-label>As at</x-label>
                        <x-input type="date" name="as_at" value="{{ $asAt->toDateString() }}" onchange="this.form.requestSubmit()" />
                    </div>
                    @isset($compareAt)
                        <div class="w-44">
                            <x-label>Compare with</x-label>
                            <x-input type="date" name="compare" value="{{ $compareAt->toDateString() }}" onchange="this.form.requestSubmit()" />
                        </div>
                    @endisset
                    <div class="flex flex-wrap gap-1.5 pb-0.5">
                        @foreach($quick as $label => $date)
                            <a href="{{ request()->url().'?'.http_build_query(['as_at' => $date->toDateString()]) }}" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium transition {{ $asAt->isSameDay($date) ? 'border-navy-800 bg-navy-800 text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-orange-400' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </form>
            @else
                @include('books._year', ['custom' => true])
            @endif
            @include('books._exports')
        </div>

        <section class="max-w-4xl mx-auto bg-white rounded-2xl border border-gray-200 shadow-sm">
            <div class="px-6 py-5 border-b border-gray-100 text-center">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ $report->entity }}</p>
                @if($report->entityLine)<p class="text-xs text-gray-400">{{ $report->entityLine }}</p>@endif
                <h2 class="mt-1 text-lg font-bold text-gray-900">{{ $report->title }}</h2>
                <p class="text-sm text-gray-500">{{ $report->period }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-100">
                            @if($report->showCodes)<th class="pl-6 pr-2 pt-4 pb-2 text-left font-semibold w-16">Code</th>@endif
                            <th class="{{ $report->showCodes ? 'px-3' : 'px-6' }} pt-4 pb-2 text-left font-semibold">{{ $report->slug === 'by-property' ? 'Property' : ($report->showCodes ? 'Account' : '') }}</th>
                            @foreach($report->columns as $heading)<th class="px-6 pt-4 pb-2 text-right font-semibold w-40">{{ $heading }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report->sections as $section)
                            @if($section['title'])
                                <tr><td colspan="{{ $span }}" class="px-6 pt-5 pb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $section['title'] }}</td></tr>
                            @endif
                            @forelse($section['rows'] as $line)
                                <tr class="hover:bg-gray-50/60">
                                    @if($report->showCodes)<td class="pl-6 pr-2 py-1.5 font-mono text-gray-400">{{ $line['code'] }}</td>@endif
                                    <td class="{{ $report->showCodes ? 'px-3' : 'px-6' }} py-1.5">
                                        @if(is_string($line['account_id'] ?? null))
                                            <a href="{{ route('books.account', [$line['account_id']] + $linkQuery) }}" class="text-gray-700 hover:text-orange-600">{{ $line['name'] }}</a>
                                        @else
                                            <span class="text-gray-700">{{ $line['name'] }}</span>
                                        @endif
                                        @if(!empty($line['note']))<span class="block text-xs text-gray-400">{{ $line['note'] }}</span>@endif
                                    </td>
                                    @foreach($line['values'] as $i => $v)
                                        <td class="px-6 py-1.5 text-right whitespace-nowrap {{ $report->slug === 'by-property' && $i === $last ? ($v < 0 ? 'text-red-600 font-medium' : 'text-gray-900 font-medium') : ($report->slug === 'balance-sheet' && $i > 0 ? 'text-gray-400' : 'text-gray-900') }}">{{ $n($v) }}</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ $span }}" class="px-6 py-2 text-sm text-gray-400">Nothing recorded in this period.</td></tr>
                            @endforelse
                            @if($section['total_label'])
                                <tr class="border-t border-gray-300 font-semibold">
                                    @if($report->showCodes)<td></td>@endif
                                    <td class="{{ $report->showCodes ? 'px-3' : 'px-6' }} py-2">{{ $section['total_label'] }}</td>
                                    @foreach($report->columns as $i => $_)
                                        <td class="px-6 py-2 text-right whitespace-nowrap {{ $report->slug === 'balance-sheet' && $i > 0 ? 'text-gray-400' : '' }}">{{ $n($report->sectionTotal($section['key'], $i)) }}</td>
                                    @endforeach
                                </tr>
                            @endif
                        @endforeach
                        @foreach($report->totals as $total)
                            <tr class="{{ $total['grand'] ? 'border-t-2 border-gray-900 text-base' : 'border-t border-gray-200' }} font-bold bg-orange-50/50">
                                @if($report->showCodes)<td></td>@endif
                                <td class="{{ $report->showCodes ? 'px-3' : 'px-6' }} py-3">{{ $total['label'] }}</td>
                                @foreach($report->columns as $i => $_)
                                    @php $v = $report->totalValue($total, $i); @endphp
                                    <td class="px-6 py-3 text-right whitespace-nowrap {{ $report->slug === 'balance-sheet' && $i > 0 ? 'text-gray-400' : $colour($v) }}">{{ $n($v) }}</td>
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
</x-app-layout>
