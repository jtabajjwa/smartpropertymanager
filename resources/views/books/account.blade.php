<x-app-layout>
    <x-slot name="header">{{ $account['name'] }}</x-slot>

    @php
        $n = fn ($v) => abs((float) $v) < 0.005 ? '–' : (($v < 0 ? '(' : '').number_format(abs((float) $v)).($v < 0 ? ')' : ''));
    @endphp

    <div class="max-w-6xl mx-auto space-y-6">
        @include('books._nav')

        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            @include('books._year', ['custom' => true])
            <a href="{{ route('books.accounts') }}" class="text-sm font-medium text-orange-600 hover:text-orange-700">&larr; Chart of accounts</a>
        </div>

        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
            <div class="flex flex-col gap-1 px-6 py-5 border-b border-gray-100 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="font-mono text-xs text-gray-400">{{ $account['code'] }} · {{ \App\Accounting\LandlordLedger::TYPES[$account['type']] ?? '' }}</p>
                    <h2 class="text-lg font-bold text-gray-900">{{ $account['name'] }}</h2>
                    @if($account['description'])<p class="text-sm text-gray-500">{{ $account['description'] }}</p>@endif
                </div>
                <p class="text-sm text-gray-500">{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-100">
                            <th class="px-6 pt-4 pb-2 text-left font-semibold w-32">Date</th>
                            <th class="px-3 pt-4 pb-2 text-left font-semibold">Details</th>
                            <th class="px-6 pt-4 pb-2 text-right font-semibold w-36">Debit</th>
                            <th class="px-6 pt-4 pb-2 text-right font-semibold w-36">Credit</th>
                            <th class="px-6 pt-4 pb-2 text-right font-semibold w-40">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr class="bg-gray-50/60 text-gray-600">
                            <td class="px-6 py-2">{{ $from->format('d M Y') }}</td>
                            <td class="px-3 py-2 font-medium" colspan="3">Opening balance</td>
                            <td class="px-6 py-2 text-right whitespace-nowrap font-medium">{{ $n($opening) }}</td>
                        </tr>
                        @forelse($lines as $line)
                            <tr class="hover:bg-gray-50/60">
                                <td class="px-6 py-2 whitespace-nowrap text-gray-600">{{ \Carbon\Carbon::parse($line['date'])->format('d M Y') }}</td>
                                <td class="px-3 py-2"><span class="text-gray-800">{{ $line['memo'] }}</span> <span class="text-xs text-gray-400">{{ $line['ref'] }}</span></td>
                                <td class="px-6 py-2 text-right whitespace-nowrap text-gray-900">{{ $n($line['debit']) }}</td>
                                <td class="px-6 py-2 text-right whitespace-nowrap text-gray-900">{{ $n($line['credit']) }}</td>
                                <td class="px-6 py-2 text-right whitespace-nowrap text-gray-700">{{ $n($line['balance']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-6 text-center text-sm text-gray-400">No entries in this period.</td></tr>
                        @endforelse
                        <tr class="border-t-2 border-gray-900 font-bold bg-orange-50/50">
                            <td class="px-6 py-3">{{ $to->format('d M Y') }}</td>
                            <td class="px-3 py-3">Closing balance</td>
                            <td class="px-6 py-3 text-right whitespace-nowrap">{{ $n($lines->sum('debit')) }}</td>
                            <td class="px-6 py-3 text-right whitespace-nowrap">{{ $n($lines->sum('credit')) }}</td>
                            <td class="px-6 py-3 text-right whitespace-nowrap">{{ $n($closing) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            @if(in_array($account['type'], ['income', 'expense'], true))
                <p class="px-6 py-3 text-xs text-gray-400">Income and expense accounts start each fiscal year at nil; earlier years move to Retained earnings.</p>
            @endif
        </section>
    </div>
</x-app-layout>
