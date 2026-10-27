{{-- Which unit an invoice is for — shown only to tenants renting more than one unit. --}}
@props(['lease'])

@if(($portalMultiUnit ?? false) && $lease?->unit)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-600 whitespace-nowrap']) }}>Unit {{ $lease->unit->name }}@if($lease->unit->estate) · {{ $lease->unit->estate->name }}@endif</span>
@endif
