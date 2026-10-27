<x-app-layout>
    <x-slot name="header">Chart of accounts</x-slot>

    @php
        $n = fn ($v) => abs((float) $v) < 0.005 ? '–' : (($v < 0 ? '(' : '').number_format(abs((float) $v)).($v < 0 ? ')' : ''));
        $fyStart = $books->fiscalYearRange($books->fiscalYearOf($asAt))[0];
        $linkQuery = ['from' => $fyStart->toDateString(), 'to' => $asAt->toDateString()];
    @endphp

    <div class="max-w-6xl mx-auto space-y-6">
        @include('books._nav')

        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <form method="GET" class="flex items-end gap-3">
                <div class="w-44">
                    <x-label>Balances as at</x-label>
                    <x-input type="date" name="as_at" value="{{ $asAt->toDateString() }}" onchange="this.form.requestSubmit()" />
                </div>
            </form>
            <p class="max-w-xl text-xs text-gray-500">{{ config('app.name') }} keeps these accounts for you from what you record ({{ strtolower($ledger->basisLabel()) }}). Each expense item is its own expense account. Income and expenses show {{ $books->fiscalYearLabel($books->fiscalYearOf($asAt)) }} to date.</p>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach(\App\Accounting\LandlordLedger::TYPES as $type => $title)
                @php $group = $accounts->where('type', $type); @endphp
                @continue($group->isEmpty())
                <section class="bg-white rounded-2xl border border-gray-200 shadow-sm {{ in_array($type, ['expense'], true) ? 'lg:col-span-2' : '' }}">
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-gray-100">
                        <h2 class="text-sm font-semibold text-gray-900">{{ $title }}</h2>
                        <span class="text-xs text-gray-400">{{ $group->count() }} account{{ $group->count() === 1 ? '' : 's' }}</span>
                    </div>
                    <ul class="divide-y divide-gray-50">
                        @foreach($group as $a)
                            <li>
                                <a href="{{ route('books.account', [$a['key']] + $linkQuery) }}" class="flex items-start gap-3 px-5 py-2.5 hover:bg-gray-50/70 transition group">
                                    <span class="w-12 shrink-0 pt-0.5 font-mono text-xs text-gray-400">{{ $a['code'] }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-gray-800 group-hover:text-orange-600">{{ $a['name'] }}</span>
                                        @if($a['description'])<span class="block text-xs text-gray-400">{{ $a['description'] }}</span>@endif
                                    </span>
                                    <span class="shrink-0 pt-0.5 text-sm text-right whitespace-nowrap {{ ($balances[$a['key']] ?? 0) < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $n($balances[$a['key']] ?? 0) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    </div>
</x-app-layout>
