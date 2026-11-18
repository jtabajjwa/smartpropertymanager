<div class="mb-6 space-y-4">
    <div>
        <h3 class="text-lg font-semibold text-gray-900">Partners</h3>
        <p class="mt-1 text-sm text-gray-500">Marketing partners and agents who bring landlords in, their codes, and the promotions landlords get with them.</p>
    </div>
    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200">
        @foreach(['staff.partners.index' => 'Partners', 'staff.commissions.index' => 'Commissions', 'staff.promotions.index' => 'Promotions & campaign codes'] as $route => $label)
            @php $active = request()->routeIs($route) || ($route === 'staff.partners.index' && request()->routeIs('staff.partners.show')); @endphp
            <a href="{{ route($route) }}" class="px-4 py-2.5 -mb-px border-b-2 text-sm font-medium whitespace-nowrap {{ $active ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-900 hover:border-gray-300' }}">{{ $label }}</a>
        @endforeach
    </nav>
</div>
