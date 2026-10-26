<x-staff-layout>
    <x-slot name="header">{{ $landlord->name }}</x-slot>

    <div class="max-w-7xl mx-auto">
        @include('staff.landlords.partials.workspace-header')

        {{-- Headline metrics --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-stat-card label="Units" :value="number_format($summary['units'])" color="orange">
                <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg></x-slot>
                <x-slot name="sub">across {{ $summary['estates'] }} {{ Str::plural('estate', $summary['estates']) }} in {{ $summary['locations'] }} {{ Str::plural('location', $summary['locations']) }}</x-slot>
            </x-stat-card>

            <x-stat-card label="Occupancy" :value="$summary['occupancy_rate'].'%'" color="green">
                <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg></x-slot>
                <x-slot name="sub">{{ $summary['occupied'] }} of {{ $summary['units'] }} units let</x-slot>
            </x-stat-card>

            <x-stat-card label="Vacant" :value="number_format($summary['vacant'])" color="blue">
                <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg></x-slot>
                <x-slot name="sub">{{ $summary['maintenance'] }} more under maintenance</x-slot>
            </x-stat-card>

            <x-stat-card label="Est. monthly rent roll" :value="'UGX '.number_format($summary['monthly_rent_roll'])" color="gray">
                <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/></svg></x-slot>
                <x-slot name="sub">from {{ $summary['active_leases'] }} active {{ Str::plural('lease', $summary['active_leases']) }}</x-slot>
            </x-stat-card>
        </div>

        <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Unit status breakdown --}}
            <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-900">Unit status</h3>
                @php
                    $pct = fn ($n) => $summary['units'] > 0 ? round($n / $summary['units'] * 100, 1) : 0;
                @endphp
                @if($summary['units'] > 0)
                    <div class="mt-4 flex h-3 rounded-full overflow-hidden bg-gray-100" role="img" aria-label="{{ $summary['occupied'] }} occupied, {{ $summary['vacant'] }} vacant, {{ $summary['maintenance'] }} under maintenance">
                        <div class="bg-orange-500" style="width: {{ $pct($summary['occupied']) }}%"></div>
                        <div class="bg-green-500" style="width: {{ $pct($summary['vacant']) }}%"></div>
                        <div class="bg-gray-300" style="width: {{ $pct($summary['maintenance']) }}%"></div>
                    </div>
                    <dl class="mt-4 grid grid-cols-3 gap-4">
                        <div>
                            <dt class="flex items-center gap-2 text-xs text-gray-500"><span class="w-2.5 h-2.5 rounded-sm bg-orange-500"></span>Occupied</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['occupied'] }} <span class="text-xs font-normal text-gray-500">{{ $pct($summary['occupied']) }}%</span></dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-2 text-xs text-gray-500"><span class="w-2.5 h-2.5 rounded-sm bg-green-500"></span>Vacant</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['vacant'] }} <span class="text-xs font-normal text-gray-500">{{ $pct($summary['vacant']) }}%</span></dd>
                        </div>
                        <div>
                            <dt class="flex items-center gap-2 text-xs text-gray-500"><span class="w-2.5 h-2.5 rounded-sm bg-gray-300"></span>Maintenance</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">{{ $summary['maintenance'] }} <span class="text-xs font-normal text-gray-500">{{ $pct($summary['maintenance']) }}%</span></dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-3 text-sm text-gray-500">No units yet. Start by adding a <a href="{{ route('staff.landlords.locations.index', $landlord) }}" class="text-orange-600 hover:text-orange-700">location</a>, then an <a href="{{ route('staff.landlords.estates.index', $landlord) }}" class="text-orange-600 hover:text-orange-700">estate</a> and its <a href="{{ route('staff.landlords.units.index', $landlord) }}" class="text-orange-600 hover:text-orange-700">units</a>.</p>
                @endif
            </div>

            {{-- Tenancy + plan --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-900">Tenancy</h3>
                <dl class="mt-3 divide-y divide-gray-100 text-sm">
                    <div class="flex justify-between py-2"><dt class="text-gray-500">Tenants</dt><dd class="font-medium text-gray-900">{{ number_format($summary['tenants']) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-gray-500">Active leases</dt><dd class="font-medium text-gray-900">{{ number_format($summary['active_leases']) }}</dd></div>
                    <div class="flex justify-between py-2"><dt class="text-gray-500">Expiring in 30 days</dt><dd class="font-medium {{ $summary['expiring_leases'] > 0 ? 'text-orange-600' : 'text-gray-900' }}">{{ number_format($summary['expiring_leases']) }}</dd></div>
                    @if($plan)
                        <div class="flex justify-between py-2 gap-3">
                            <dt class="text-gray-500">Plan tier by units</dt>
                            <dd class="font-medium text-gray-900 text-right">
                                @if($tier)
                                    {{ $tier->max_units === null ? $tier->min_units.'+' : $tier->min_units.'–'.$tier->max_units }} units
                                    <span class="block text-xs font-normal text-gray-500">{{ $plan->currency }} {{ number_format($tier->monthly_price) }} / month</span>
                                @else
                                    <span class="text-gray-500">—</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>

        {{-- Per-estate breakdown --}}
        <div class="mt-4 bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
                <h3 class="text-sm font-semibold text-gray-900">Estates</h3>
                <a href="{{ route('staff.landlords.estates.index', $landlord) }}" class="text-sm text-orange-600 hover:text-orange-700 transition">Manage estates &rarr;</a>
            </div>

            @if($estates->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500">No estates yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Estate</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Location</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Units</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Occupied</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Vacant</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Maint.</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-48">Occupancy</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($estates as $estate)
                                @php $rate = $estate->units_count > 0 ? round($estate->occupied_count / $estate->units_count * 100) : 0; @endphp
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-5 py-3 text-sm font-medium text-gray-900">
                                        <a href="{{ route('staff.landlords.units.index', [$landlord, 'estate_id' => $estate->id]) }}" class="hover:text-orange-600 transition">{{ $estate->name }}</a>
                                    </td>
                                    <td class="px-5 py-3 text-sm text-gray-600">{{ $estate->location?->name ?? '--' }} <x-map-link :estate="$estate" class="ml-1" /></td>
                                    <td class="px-5 py-3 text-sm text-gray-900 text-right">{{ $estate->units_count }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-600 text-right">{{ $estate->occupied_count }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-600 text-right">{{ $estate->vacant_count }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-600 text-right">{{ $estate->maintenance_count }}</td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden">
                                                <div class="h-full rounded-full {{ $rate >= 80 ? 'bg-green-500' : ($rate >= 50 ? 'bg-orange-500' : 'bg-red-500') }}" style="width: {{ $rate }}%"></div>
                                            </div>
                                            <span class="w-10 text-right text-xs font-medium text-gray-700">{{ $rate }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <p class="mt-3 text-xs text-gray-400">Monthly rent roll converts each active lease's rent to a monthly figure (e.g. quarterly ÷ 3), so it's an estimate for non-monthly leases.</p>
    </div>
</x-staff-layout>
