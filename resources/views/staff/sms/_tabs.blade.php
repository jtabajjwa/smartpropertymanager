@php
    $tabs = [
        'staff.sms.index' => 'Stock & bundles',
        'staff.sms.landlords' => 'Landlord balances',
    ];
@endphp
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <h3 class="text-lg font-semibold text-gray-900">SMS</h3>
        <p class="mt-1 text-sm text-gray-500">SMS bought from providers and resold to landlords as prepaid credit.</p>
    </div>
    <nav class="inline-flex gap-1 p-1 rounded-xl bg-gray-100 self-start sm:self-auto">
        @foreach($tabs as $route => $label)
            <a href="{{ route($route) }}" class="px-4 py-2 rounded-lg text-sm font-medium transition {{ request()->routeIs($route) || ($route === 'staff.sms.landlords' && request()->routeIs('staff.sms.landlord')) ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800' }}">{{ $label }}</a>
        @endforeach
    </nav>
</div>
