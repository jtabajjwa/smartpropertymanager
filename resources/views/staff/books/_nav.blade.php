@php
    $tabs = [
        'staff.books.index' => 'Overview',
        'staff.books.accounts' => 'Chart of accounts',
        'staff.books.journal' => 'Journal',
        'staff.books.pnl' => 'Profit & Loss',
        'staff.books.balance-sheet' => 'Balance sheet',
        'staff.books.trial-balance' => 'Trial balance',
        'staff.books.tax-rates' => 'Landlord tax rates',
    ];
@endphp
<div class="mb-6 space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">Books</h3>
            <p class="mt-1 text-sm text-gray-500">Primora's double-entry books. Payments, payouts and SMS purchases post automatically.</p>
        </div>
        @staffcan('books.post')
            <div x-data="{ open: false }" class="relative">
                <x-button type="button" @click="open = !open">Record ▾</x-button>
                <div x-show="open" x-cloak @click.outside="open = false" class="absolute right-0 z-20 mt-2 w-56 rounded-xl bg-white shadow-lg ring-1 ring-gray-200 py-1 text-sm">
                    <button type="button" class="block w-full text-left px-4 py-2 hover:bg-gray-50" @click="open = false; $dispatch('books-form', 'expense')">Expense</button>
                    <button type="button" class="block w-full text-left px-4 py-2 hover:bg-gray-50" @click="open = false; $dispatch('books-form', 'transfer')">Transfer between accounts</button>
                    <button type="button" class="block w-full text-left px-4 py-2 hover:bg-gray-50" @click="open = false; $dispatch('books-form', 'journal')">Journal entry</button>
                </div>
            </div>
        @endstaffcan
    </div>
    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200" aria-label="Books sections">
        @foreach($tabs as $route => $label)
            @php $active = request()->routeIs($route) || ($route === 'staff.books.accounts' && request()->routeIs('staff.books.ledger')); @endphp
            <a href="{{ route($route) }}" class="px-4 py-2.5 -mb-px border-b-2 text-sm font-medium whitespace-nowrap transition {{ $active ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-900 hover:border-gray-300' }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
</div>

@staffcan('books.post')
    @include('staff.books._forms')
@endstaffcan
