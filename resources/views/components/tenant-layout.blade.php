@props(['title' => null, 'guest' => false])

@php
    $tenant = $portalTenant ?? null;
    $landlord = $tenant?->landlord;
    $tabs = [
        ['tenant.dashboard', 'Home', 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
        ['tenant.pay', 'Pay', 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z'],
        ['tenant.invoices', 'Invoices', 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z'],
        ['tenant.issues.index', 'Issues', 'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085'],
    ];
    $active = fn ($route) => request()->routeIs($route) || request()->routeIs($route.'.*') || ($route === 'tenant.issues.index' && request()->routeIs('tenant.issues.*')) || ($route === 'tenant.invoices' && request()->routeIs('tenant.payments'));
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1c2230">
    <title>{{ $title ? $title.' · ' : '' }}Tenant Portal · {{ config('app.name', 'Primora') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-900 min-h-screen">
    @if($guest)
        <main class="min-h-screen flex flex-col items-center justify-center px-4 py-10">
            <div class="mb-8 flex items-center gap-2">
                <x-logo class="w-9 h-9" />
                <span class="font-bold text-xl tracking-tight text-navy-800">{{ config('app.name', 'Primora') }} <span class="text-orange-500">Tenant</span></span>
            </div>
            <div class="w-full max-w-sm">
                @include('tenant-portal._flash')
                {{ $slot }}
            </div>
            <p class="mt-10 text-xs text-gray-400">Are you a landlord? <a href="{{ route('login') }}" class="text-gray-600 hover:text-orange-600">Sign in here</a></p>
        </main>
    @else
        <header class="sticky top-0 z-30 bg-navy-800 text-white">
            <div class="max-w-3xl mx-auto px-4 h-14 flex items-center gap-3">
                @if($landlord?->logoUrl())
                    <img src="{{ $landlord->logoUrl() }}" alt="" class="h-8 w-8 rounded-lg object-contain bg-white p-0.5">
                @else
                    <x-logo class="w-7 h-7" />
                @endif
                <div class="min-w-0 flex-1 leading-tight">
                    <p class="text-sm font-semibold truncate">{{ $landlord?->name }}</p>
                    <p class="text-xs text-gray-300 truncate">{{ $tenant?->name }}</p>
                </div>
                <nav class="hidden sm:flex items-center gap-1">
                    @foreach($tabs as [$route, $label])
                        <a href="{{ route($route) }}" class="px-3 py-1.5 rounded-lg text-sm font-medium {{ $active($route) ? 'bg-white/15 text-white' : 'text-gray-300 hover:text-white' }}">{{ $label }}</a>
                    @endforeach
                </nav>
                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = !open" class="p-2 -mr-2 rounded-lg text-gray-300 hover:text-white" title="Account">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute right-0 mt-2 w-56 rounded-xl bg-white text-gray-700 shadow-lg ring-1 ring-gray-200 py-1 text-sm">
                        <a href="{{ route('tenant.payments') }}" class="block px-4 py-2 hover:bg-gray-50">My payments</a>
                        @if(($portalAccounts ?? 0) > 1)
                            <a href="{{ route('tenant.accounts') }}" class="block px-4 py-2 hover:bg-gray-50">Switch tenancy</a>
                        @endif
                        <form method="POST" action="{{ route('tenant.logout') }}">
                            @csrf
                            <button type="submit" class="w-full text-left px-4 py-2 hover:bg-gray-50 text-red-600">Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-3xl mx-auto px-4 pt-5 pb-28 sm:pb-12">
            @include('tenant-portal._flash')
            {{ $slot }}
        </main>

        {{-- Bottom tabs (phones) --}}
        <nav class="sm:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-gray-200 pb-[env(safe-area-inset-bottom)]">
            <div class="grid grid-cols-4">
                @foreach($tabs as [$route, $label, $icon])
                    <a href="{{ route($route) }}" class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium {{ $active($route) ? 'text-orange-600' : 'text-gray-500' }}">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $icon }}"/></svg>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </nav>
    @endif
</body>
</html>
