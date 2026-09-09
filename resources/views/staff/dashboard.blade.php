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

        <div class="mt-6 bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-sm text-gray-600">
                Manage landlord accounts and subscription plans from the sidebar.
                <a href="{{ route('staff.landlords.index') }}" class="text-orange-500 hover:text-orange-600 transition">View landlords &rarr;</a>
            </p>
        </div>
    </div>
</x-staff-layout>
