<x-app-layout>
    <x-slot name="header">Books</x-slot>

    @php
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $n = fn ($v) => abs((float) $v) < 0.005 ? '–' : (($v < 0 ? '(' : '').number_format(abs((float) $v)).($v < 0 ? ')' : ''));
        $net = round($income - $expenses, 2);
        $taxDue = collect($taxes['lines'])->sum('due');
        $taxPaid = collect($taxes['lines'])->sum('paid');
        $label = $books->fiscalYearLabel($year);
    @endphp

    <div class="max-w-6xl mx-auto space-y-6">
        @include('books._nav')

        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            @include('books._year')
            <div class="flex flex-col items-start gap-1 lg:items-end">
                <p class="text-xs text-gray-500">Tax pack for {{ $label }}: income statement, by property, taxes</p>
                @include('books._exports', ['exportRoute' => 'books.pack', 'exportQuery' => ['year' => $year], 'pdfLabel' => 'Tax pack PDF', 'xlsxLabel' => 'Tax pack Excel'])
            </div>
        </div>

        @unless($configured)
            <div class="flex flex-col gap-3 rounded-2xl border border-orange-200 bg-orange-50/80 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-gray-900">Set up your taxes</p>
                    <p class="mt-0.5 text-sm text-gray-600">Choose the taxes and levies that apply where your properties are (rental income tax, local service tax, property rates, ground rent, trading licence) and {{ config('app.name') }} will work out what you owe each year.</p>
                </div>
                <a href="{{ route('books.settings') }}" class="shrink-0 inline-flex items-center rounded-xl bg-navy-800 px-4 py-2 text-sm font-medium text-white hover:bg-navy-700 transition">Choose taxes</a>
            </div>
        @endunless

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl bg-navy-800 text-white p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-300">Net income</p>
                <p class="mt-2 text-2xl font-bold {{ $net < 0 ? 'text-red-300' : '' }}">{{ $ugx($net) }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ $label }} · {{ $landlord->booksBasis() }} basis</p>
            </div>
            <x-stat-card label="Income" :value="$ugx($income)" color="green"><x-slot name="sub">{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</x-slot></x-stat-card>
            <x-stat-card label="Expenses" :value="$ugx($expenses)" color="red"><x-slot name="sub">Including {{ config('app.name') }} charges and deductible levies</x-slot></x-stat-card>
            <x-stat-card label="Taxes still to pay" :value="$ugx(max(0, $taxDue - $taxPaid))"><x-slot name="sub">{{ $ugx($taxDue) }} estimated · {{ $ugx($taxPaid) }} paid</x-slot></x-stat-card>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">Taxes &amp; levies · {{ $label }}</h2>
                    <a href="{{ route('books.taxes', ['year' => $year]) }}" class="text-sm font-medium text-orange-600 hover:text-orange-700">Details &rarr;</a>
                </div>
                @if(count($taxes['lines']))
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-xs uppercase tracking-wide text-gray-500"><th class="px-5 pt-3 pb-1.5 text-left font-semibold">Tax</th><th class="px-5 pt-3 pb-1.5 text-right font-semibold">Due</th><th class="px-5 pt-3 pb-1.5 text-right font-semibold">Balance</th></tr></thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($taxes['lines'] as $line)
                                @php $balance = round($line['due'] - $line['paid'], 2); @endphp
                                <tr>
                                    <td class="px-5 py-2"><span class="text-gray-800">{{ $line['label'] }}</span><span class="block text-xs text-gray-400">{{ $line['authority'] }}</span></td>
                                    <td class="px-5 py-2 text-right whitespace-nowrap text-gray-900">{{ $n($line['due']) }}</td>
                                    <td class="px-5 py-2 text-right whitespace-nowrap font-medium {{ $balance > 0 ? 'text-orange-600' : 'text-green-700' }}">{{ $balance > 0 ? $n($balance) : 'Paid' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="px-5 py-3 text-xs text-gray-400">Estimates from gross rental income of {{ $ugx($taxes['gross_rental']) }}. Confirm with URA and your local council before filing.</p>
                @else
                    <p class="px-5 py-8 text-center text-sm text-gray-500">No taxes switched on yet. <a href="{{ route('books.settings') }}" class="font-medium text-orange-600 hover:text-orange-700">Choose taxes</a></p>
                @endif
            </section>

            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">By property · {{ $label }}</h2>
                    <a href="{{ route('books.by-property', ['year' => $year]) }}" class="text-sm font-medium text-orange-600 hover:text-orange-700">Details &rarr;</a>
                </div>
                @php $rows = $byProperty->sections[0]['rows'] ?? []; @endphp
                @if(count($rows))
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-xs uppercase tracking-wide text-gray-500"><th class="px-5 pt-3 pb-1.5 text-left font-semibold">Property</th><th class="px-5 pt-3 pb-1.5 text-right font-semibold">Income</th><th class="px-5 pt-3 pb-1.5 text-right font-semibold">Net</th></tr></thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($rows as $row)
                                <tr>
                                    <td class="px-5 py-2 text-gray-800">{{ $row['name'] }}</td>
                                    <td class="px-5 py-2 text-right whitespace-nowrap text-gray-900">{{ $n($row['values'][0]) }}</td>
                                    <td class="px-5 py-2 text-right whitespace-nowrap font-medium {{ $row['values'][2] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $n($row['values'][2]) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="px-5 py-8 text-center text-sm text-gray-500">Nothing recorded in {{ $label }}.</p>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
