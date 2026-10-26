{{-- Landlord workspace header: identity, status and section tabs. Expects $landlord. --}}
@php
    $tabs = [
        'show' => 'Overview',
        'locations.index' => 'Locations',
        'estates.index' => 'Estates',
        'units.index' => 'Units',
    ];
@endphp

<div class="mb-6">
    <a href="{{ route('staff.landlords.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900 transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        All landlords
    </a>

    <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-xl font-semibold text-gray-900 break-words">{{ $landlord->name }}</h2>
                <x-badge :color="$landlord->status === 'active' ? 'green' : 'red'">{{ ucfirst($landlord->status) }}</x-badge>
                <x-badge color="gray">Subscription: {{ ucfirst($landlord->subscription_status) }}</x-badge>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                @if($landlord->trading_name){{ $landlord->trading_name }} · @endif{{ $landlord->contact_email }}@if($landlord->contact_phone) · {{ $landlord->contact_phone }}@endif
            </p>
        </div>
        <div class="flex flex-col items-stretch gap-2 sm:items-end shrink-0">
            @staffcan('portal.access')<form method="POST" action="{{ route('staff.landlords.portal', $landlord) }}">
                @csrf
                <x-button type="submit" class="w-full sm:w-auto justify-center">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                    Open landlord portal
                </x-button>
            </form>@endstaffcan
            <p class="text-xs text-gray-500 sm:text-right max-w-xs">Leases, invoices, payments, deposits, expenses and more, as the landlord sees them. Changes are recorded under your name.</p>
        </div>
    </div>

    <nav class="mt-5 flex gap-1 border-b border-gray-200 overflow-x-auto" aria-label="Landlord sections">
        @foreach($tabs as $route => $label)
            @php $active = request()->routeIs('staff.landlords.'.$route); @endphp
            <a href="{{ route('staff.landlords.'.$route, $landlord) }}"
               class="px-4 py-2.5 -mb-px border-b-2 text-sm font-medium whitespace-nowrap transition {{ $active ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-900 hover:border-gray-300' }}"
               @if($active) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
</div>
