<x-staff-layout>
    <x-slot name="header">Dashboard</x-slot>

    <div class="max-w-7xl mx-auto">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-stat-card label="Total Landlords" :value="$totalLandlords" color="orange">
                <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 21h19.5M3.75 3v18m4.5-18v18m4.5-18v18m4.5-18v18M5.25 3h13.5M5.25 21h13.5"/></svg></x-slot>
            </x-stat-card>

            <x-stat-card label="Active" :value="$activeLandlords" color="green">
                <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></x-slot>
            </x-stat-card>

            <x-stat-card label="Suspended" :value="$suspendedLandlords" color="red">
                <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg></x-slot>
            </x-stat-card>

            <x-stat-card label="Trialing" :value="$trialingLandlords" color="blue">
                <x-slot name="icon"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></x-slot>
            </x-stat-card>
        </div>

        {{-- ===== Property portfolio (all landlords) ===== --}}
        @php
            $pct = fn ($n) => $portfolio['units'] > 0 ? round($n / $portfolio['units'] * 100, 1) : 0;
        @endphp

        <div class="mt-8 flex items-end justify-between">
            <div>
                <h2 class="text-base font-semibold text-gray-900">Property portfolio</h2>
                <p class="mt-0.5 text-sm text-gray-500">Every estate and unit managed on {{ config('app.name') }}, across all landlords.</p>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Estates</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ number_format($portfolio['estates']) }}</p>
                <p class="mt-1 text-xs text-gray-500">in {{ number_format($portfolio['districts']) }} {{ Str::plural('district', $portfolio['districts']) }}</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Units</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ number_format($portfolio['units']) }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ number_format($portfolio['vacant']) }} vacant · {{ number_format($portfolio['maintenance']) }} maint.</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Occupancy</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $portfolio['occupancy_rate'] }}%</p>
                <p class="mt-1 text-xs text-gray-500">{{ number_format($portfolio['occupied']) }} units let</p>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Active leases</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ number_format($portfolio['active_leases']) }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ number_format($portfolio['tenants']) }} tenants · {{ number_format($portfolio['expiring_leases']) }} expiring in 30d</p>
            </div>
            <div class="col-span-2 lg:col-span-1 bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Rent under mgmt.</p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><span class="text-sm font-semibold text-gray-500">UGX</span> {{ number_format($portfolio['monthly_rent_roll']) }}</p>
                <p class="mt-1 text-xs text-gray-500">estimated per month</p>
            </div>
        </div>

        {{-- Unit status bar --}}
        <div class="mt-4 bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-900">Unit status across the platform</h3>
                <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-orange-500"></span>Occupied {{ $pct($portfolio['occupied']) }}%</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-green-500"></span>Vacant {{ $pct($portfolio['vacant']) }}%</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-gray-300"></span>Maintenance {{ $pct($portfolio['maintenance']) }}%</span>
                </div>
            </div>
            <div class="mt-4 flex h-3 rounded-full overflow-hidden bg-gray-100">
                <div class="bg-orange-500" style="width: {{ $pct($portfolio['occupied']) }}%"></div>
                <div class="bg-green-500" style="width: {{ $pct($portfolio['vacant']) }}%"></div>
                <div class="bg-gray-300" style="width: {{ $pct($portfolio['maintenance']) }}%"></div>
            </div>
        </div>

        {{-- Largest portfolios --}}
        <div class="mt-4 bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
                <h3 class="text-sm font-semibold text-gray-900">Largest portfolios</h3>
                @staffcan('landlords.view')
                    <a href="{{ route('staff.landlords.index') }}" class="text-sm text-orange-600 hover:text-orange-700 transition">All landlords &rarr;</a>
                @endstaffcan
            </div>

            @if($topLandlords->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-gray-500">No units have been added on the platform yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Landlord</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Estates</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Units</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Vacant</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-48">Occupancy</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($topLandlords as $row)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-5 py-3 text-sm">
                                        @staffcan('properties.view')
                                            <a href="{{ route('staff.landlords.show', $row->landlord) }}" class="font-medium text-gray-900 hover:text-orange-600 transition">{{ $row->landlord->name }}</a>
                                        @else
                                            <span class="font-medium text-gray-900">{{ $row->landlord->name }}</span>
                                        @endstaffcan
                                        @if($row->landlord->status !== 'active')
                                            <x-badge color="red" class="ml-1">{{ ucfirst($row->landlord->status) }}</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-sm text-gray-600 text-right">{{ $row->estates }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-900 text-right">{{ $row->units }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-600 text-right">{{ $row->vacant }}</td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden">
                                                <div class="h-full rounded-full {{ $row->occupancy_rate >= 80 ? 'bg-green-500' : ($row->occupancy_rate >= 50 ? 'bg-orange-500' : 'bg-red-500') }}" style="width: {{ $row->occupancy_rate }}%"></div>
                                            </div>
                                            <span class="w-12 text-right text-xs font-medium text-gray-700">{{ $row->occupancy_rate }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-staff-layout>
