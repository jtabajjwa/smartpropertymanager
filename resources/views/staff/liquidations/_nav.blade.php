<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <h3 class="text-lg font-semibold text-gray-900">Payouts</h3>
        <p class="mt-1 text-sm text-gray-500">Paying landlords the rent Primora collected for them. One person approves, another executes.</p>
    </div>
    <nav class="inline-flex gap-1 p-1 rounded-xl bg-gray-100 self-start sm:self-auto">
        <a href="{{ route('staff.liquidations.index') }}" class="px-4 py-2 rounded-lg text-sm font-medium transition {{ request()->routeIs('staff.liquidations.*') ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800' }}">Requests</a>
        <a href="{{ route('staff.payout-accounts.index') }}" class="px-4 py-2 rounded-lg text-sm font-medium transition {{ request()->routeIs('staff.payout-accounts.*') ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800' }}">
            Payout accounts
            @if(($accountsToVerify ?? \App\Models\PayoutAccount::withoutGlobalScopes()->where('status', 'pending')->count()) > 0)
                <span class="ml-1 rounded-full bg-orange-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $accountsToVerify ?? \App\Models\PayoutAccount::withoutGlobalScopes()->where('status', 'pending')->count() }}</span>
            @endif
        </a>
    </nav>
</div>
