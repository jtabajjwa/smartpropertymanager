<x-staff-layout>
    <x-slot name="header">Books</x-slot>

    @php
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $sign = fn ($v) => ($v < 0 ? '−' : '').number_format(abs((float) $v));
    @endphp

    <div class="max-w-7xl mx-auto space-y-6">
        @include('staff.books._nav')

        @if(abs($unbalanced) >= 0.01)
            <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-3 text-sm text-red-800">The books are out of balance by {{ $ugx($unbalanced) }}. This should never happen; please report it.</div>
        @endif

        {{-- What the money is --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="rounded-2xl bg-white border border-gray-200 p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Money Primora holds</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $ugx($cash) }}</p>
                <p class="mt-1 text-xs text-gray-500">Yo! pool, bank, mobile money wallet and cash, per the books.</p>
            </div>
            <div class="rounded-2xl bg-white border border-gray-200 p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Belongs to landlords</p>
                <p class="mt-2 text-2xl font-bold text-orange-700">{{ $ugx($landlords) }}</p>
                <p class="mt-1 text-xs text-gray-500">Rent collected for them and not yet paid out. Not Primora's money.</p>
            </div>
            <div class="rounded-2xl bg-navy-800 text-white p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-300">Primora's own money</p>
                <p class="mt-2 text-2xl font-bold">{{ $ugx($own) }}</p>
                <p class="mt-1 text-xs text-gray-400">What Primora holds, less everything it owes.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            {{-- Balance sheet --}}
            <section class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">Balances today</h2>
                    <a href="{{ route('staff.books.accounts') }}" class="text-sm text-orange-600 hover:text-orange-700">Chart of accounts</a>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-100">
                    <div class="p-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">What Primora has</p>
                        <dl class="mt-3 space-y-2 text-sm">
                            @foreach($balances->where('type', 'asset') as $a)
                                <div class="flex justify-between gap-3"><dt><a href="{{ route('staff.books.ledger', $a) }}" class="text-gray-700 hover:text-orange-600">{{ $a->name }}</a></dt><dd class="font-medium {{ $a->balance < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $sign($a->balance) }}</dd></div>
                            @endforeach
                            <div class="flex justify-between gap-3 pt-2 border-t border-gray-100 font-semibold"><dt>Total</dt><dd>{{ $sign($cash) }}</dd></div>
                        </dl>
                    </div>
                    <div class="p-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Who it belongs to</p>
                        <dl class="mt-3 space-y-2 text-sm">
                            @foreach($balances->whereIn('type', ['liability', 'equity']) as $a)
                                <div class="flex justify-between gap-3"><dt><a href="{{ route('staff.books.ledger', $a) }}" class="text-gray-700 hover:text-orange-600">{{ $a->name }}</a></dt><dd class="font-medium text-gray-900">{{ $sign($a->balance) }}</dd></div>
                            @endforeach
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">Profit to date (retained)</dt><dd class="font-medium {{ $retained < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $sign($retained) }}</dd></div>
                            <div class="flex justify-between gap-3 pt-2 border-t border-gray-100 font-semibold"><dt>Total</dt><dd>{{ $sign($liabilities + $equity + $retained) }}</dd></div>
                        </dl>
                        <p class="mt-3 text-xs {{ abs($cash - ($liabilities + $equity + $retained)) < 0.01 ? 'text-green-700' : 'text-red-600' }}">
                            {{ abs($cash - ($liabilities + $equity + $retained)) < 0.01 ? '✓ Both sides agree' : 'Sides differ by '.$ugx($cash - ($liabilities + $equity + $retained)) }}
                        </p>
                    </div>
                </div>
            </section>

            {{-- This month --}}
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">{{ now()->format('F') }} so far</h2>
                    <a href="{{ route('staff.books.pnl') }}" class="text-sm text-orange-600 hover:text-orange-700">Full P&amp;L</a>
                </div>
                <dl class="p-6 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-600">Income</dt><dd class="font-medium text-gray-900">{{ $sign($month['total_income']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600">Expenses</dt><dd class="font-medium text-gray-900">{{ $sign($month['total_expense']) }}</dd></div>
                    <div class="flex justify-between pt-2 border-t border-gray-100 text-base font-bold"><dt>{{ $month['profit'] >= 0 ? 'Profit' : 'Loss' }}</dt><dd class="{{ $month['profit'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $ugx(abs($month['profit'])) }}</dd></div>
                </dl>
            </section>
        </div>

        {{-- Recent entries --}}
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-900">Latest entries</h2>
                <a href="{{ route('staff.books.journal') }}" class="text-sm text-orange-600 hover:text-orange-700">Journal</a>
            </div>
            @forelse($recent as $e)
                <div class="px-6 py-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }} flex items-center justify-between gap-4 text-sm">
                    <div class="min-w-0">
                        <p class="text-gray-900 truncate">{{ $e->description }}</p>
                        <p class="text-xs text-gray-500">{{ $e->number }} · {{ $e->entry_date->format('d M Y') }} · {{ $e->sourceLabel() }}</p>
                    </div>
                    <p class="font-medium text-gray-900 whitespace-nowrap">{{ number_format($e->total()) }}</p>
                </div>
            @empty
                <p class="px-6 py-10 text-center text-sm text-gray-500">No entries yet.</p>
            @endforelse
        </section>
    </div>
</x-staff-layout>
