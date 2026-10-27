{{-- Tabs across the landlord's Books pages. --}}
<div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Books</h1>
        <p class="mt-1 text-sm text-gray-500">Your income, expenses and taxes, worked out from what you already record. Ready for filing at the end of each fiscal year.</p>
    </div>
</div>
<nav class="flex gap-1 overflow-x-auto rounded-xl bg-white/70 p-1 ring-1 ring-gray-200 w-fit max-w-full">
    @foreach(['books.index' => 'Overview', 'books.income-statement' => 'Income statement', 'books.balance-sheet' => 'Balance sheet', 'books.trial-balance' => 'Trial balance', 'books.by-property' => 'By property', 'books.accounts' => 'Chart of accounts', 'books.taxes' => 'Taxes & levies', 'books.settings' => 'Settings'] as $route => $label)
        <a href="{{ route($route, request()->only('year')) }}" class="whitespace-nowrap rounded-lg px-3.5 py-1.5 text-sm font-medium transition {{ request()->routeIs($route) || ($route === 'books.accounts' && request()->routeIs('books.account')) ? 'bg-navy-800 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900' }}">{{ $label }}@if(in_array($route, ['books.balance-sheet', 'books.trial-balance', 'books.accounts'], true) && !\App\Support\PlanFeatures::allows('books_advanced')) <x-plan-lock class="ml-0.5 -mt-0.5" />@endif</a>
    @endforeach
</nav>
