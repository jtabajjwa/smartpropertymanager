@props(['title' => null, 'subtitle' => null, 'guest' => false])

@php
    $tenant = $portalTenant ?? null;
    $landlord = $tenant?->landlord;
    $icons = [
        'home' => 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'pay' => 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z',
        'invoices' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
        'payments' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'issues' => 'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085',
    ];
    // [route, label, icon, also active on]
    $nav = [
        ['tenant.dashboard', 'Home', 'home', []],
        ['tenant.pay', 'Pay', 'pay', []],
        ['tenant.invoices', 'Invoices', 'invoices', []],
        ['tenant.payments', 'Payments', 'payments', []],
        ['tenant.issues.index', 'Issues', 'issues', ['tenant.issues.*']],
    ];
    // Phones: four tabs; Payments sits under Invoices.
    $tabs = [$nav[0], $nav[1], [$nav[2][0], $nav[2][1], $nav[2][2], ['tenant.payments']], $nav[4]];
    $isActive = fn (array $item) => request()->routeIs($item[0]) || collect($item[3])->contains(fn ($r) => request()->routeIs($r));
    $initials = collect(preg_split('/\s+/', trim((string) $tenant?->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
    $copyright = '© '.now()->year.' '.config('app.name', 'Primora').'. All rights reserved.';
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-shell" content="tenant">
    <meta name="theme-color" content="#1c2230">
    <title>{{ $title ? $title.' · ' : '' }}Tenant Portal · {{ config('app.name', 'Primora') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/tenant.js'])
</head>
<body class="app-bg {{ $guest ? '' : 'opaque-sidebar' }} font-sans antialiased text-gray-900 min-h-screen" hx-boost="true">
    <div id="app-progress" aria-hidden="true"></div>
    @if($guest)
        <main class="min-h-screen lg:grid lg:grid-cols-2">
            {{-- Brand panel (desktop) --}}
            <div class="hidden lg:flex flex-col justify-between bg-navy-800 text-white p-12 relative overflow-hidden">
                <div class="flex items-center gap-4">
                    <x-brand :dark="true" />
                    <span class="rounded-md bg-orange-500/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-orange-300">Tenant portal</span>
                </div>
                <div class="relative z-10 max-w-md">
                    <h2 class="text-4xl font-bold leading-tight">Your home, your account, all in one place.</h2>
                    <ul class="mt-8 space-y-4 text-gray-300">
                        @foreach(['See your invoices and what you owe at a glance', 'Pay rent by MTN or Airtel Money in seconds', 'Report repairs and follow them to the fix', 'Open your tenancy agreement anytime'] as $point)
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-orange-500/20 text-orange-400">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                </span>
                                {{ $point }}
                            </li>
                        @endforeach
                    </ul>
                </div>
                <p class="relative z-10 text-xs text-gray-400">Are you a landlord? <a href="{{ route('login') }}" hx-boost="false" class="text-gray-200 hover:text-orange-400">Sign in here</a> <span class="mx-2 text-gray-600">·</span> {{ $copyright }}</p>
                <svg class="absolute -right-24 -bottom-24 w-[28rem] h-[28rem] text-white/[0.04]" viewBox="0 0 120 120" fill="none"><path d="M15 55 L60 15 L105 55" stroke="currentColor" stroke-width="11" stroke-linecap="square"/><path d="M35 72 L60 50 L85 72" stroke="currentColor" stroke-width="10" stroke-linecap="square"/><rect x="54" y="83" width="12" height="17" rx="1" fill="currentColor"/></svg>
            </div>

            <div class="min-h-screen flex flex-col items-center justify-center px-4 py-10">
                <div class="mb-8 text-center lg:hidden">
                    <x-logo icon-size="w-12 h-12" wordmark-size="text-2xl" />
                    <span class="mt-3 inline-block rounded-md bg-orange-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-orange-700">Tenant portal</span>
                </div>
                <div class="w-full max-w-sm">
                    @include('tenant-portal._flash')
                    {{ $slot }}
                </div>
                <p class="mt-10 text-xs text-gray-400 lg:hidden">Are you a landlord? <a href="{{ route('login') }}" hx-boost="false" class="text-gray-600 hover:text-orange-600">Sign in here</a></p>
                <p class="mt-2 text-[11px] text-gray-400 lg:hidden">{{ $copyright }}</p>
            </div>
        </main>
    @else
        {{-- Sidebar (desktop) --}}
        <aside class="hidden lg:flex fixed inset-y-0 left-0 z-30 w-64 flex-col bg-navy-800 text-white">
            <a href="{{ route('tenant.dashboard') }}" class="h-16 px-5 flex items-center gap-3 border-b border-white/10" aria-label="{{ config('app.name', 'Primora') }} home">
                <x-brand :dark="true" />
                <span class="ml-auto rounded-md bg-orange-500/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-orange-300">Tenant</span>
            </a>

            <div class="mx-3 mt-4 flex items-center gap-3 rounded-xl bg-white/5 px-3 py-3">
                @if($landlord?->logoUrl())
                    <img src="{{ $landlord->logoUrl() }}" alt="" class="h-9 w-9 shrink-0 rounded-lg object-contain bg-white p-0.5">
                @else
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 text-xs font-semibold">{{ mb_strtoupper(mb_substr((string) $landlord?->name, 0, 2)) }}</span>
                @endif
                <div class="min-w-0 leading-tight">
                    <p class="text-[10px] uppercase tracking-wider text-gray-400">Your landlord</p>
                    <p class="text-sm font-semibold truncate">{{ $landlord?->name }}</p>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                @foreach($nav as $item)
                    <a href="{{ route($item[0]) }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ $isActive($item) ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/5 hover:text-white' }}">
                        <svg class="w-5 h-5 {{ $isActive($item) ? 'text-orange-400' : 'text-gray-400 group-hover:text-gray-200' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $icons[$item[2]] }}"/></svg>
                        {{ $item[1] }}
                    </a>
                @endforeach

                @if($landlord && ($landlord->contact_phone || $landlord->contact_email))
                    <div class="mt-8 mx-1 rounded-xl bg-white/5 p-4 text-xs text-gray-300">
                        <p class="font-semibold text-white">Need your landlord?</p>
                        @if($landlord->contact_phone)<a href="tel:{{ preg_replace('/\s+/', '', $landlord->contact_phone) }}" class="mt-2 block hover:text-white">{{ $landlord->contact_phone }}</a>@endif
                        @if($landlord->contact_email)<a href="mailto:{{ $landlord->contact_email }}" class="mt-1 block truncate hover:text-white">{{ $landlord->contact_email }}</a>@endif
                    </div>
                @endif
            </nav>

            <div class="border-t border-white/10 p-4" x-data="{ open: false }">
                <div class="relative">
                    <button type="button" @click="open = !open" class="w-full flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-white/5 text-left">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-orange-500 text-sm font-semibold">{{ $initials ?: '?' }}</span>
                        <span class="min-w-0 flex-1 leading-tight">
                            <span class="block text-sm font-medium truncate">{{ $tenant?->name }}</span>
                            <span class="block text-xs text-gray-400 truncate">{{ $tenant?->primary_phone }}</span>
                        </span>
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9"/></svg>
                    </button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute bottom-full left-0 right-0 mb-2 rounded-xl bg-white text-gray-700 shadow-lg ring-1 ring-gray-200 py-1 text-sm">
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
        </aside>

        {{-- Top bar (phones & tablets) --}}
        <header class="lg:hidden sticky top-0 z-30 bg-navy-800 text-white">
            <div class="max-w-3xl mx-auto px-4 h-14 flex items-center gap-3">
                <a href="{{ route('tenant.dashboard') }}" class="shrink-0" title="{{ config('app.name', 'Primora') }}"><x-brand :dark="true" :wordmark="false" size="sm" /></a>
                <div class="min-w-0 flex-1 leading-tight">
                    <p class="text-sm font-semibold truncate">{{ $landlord?->name }}</p>
                    <p class="text-xs text-gray-300 truncate">{{ $tenant?->name }}</p>
                </div>
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

        <div class="lg:pl-64">
            {{-- Page header (desktop) --}}
            @if($title)
                <div class="hidden lg:block sticky top-0 z-20 bg-white/55 backdrop-blur-xl border-b border-white/70 shadow-[0_1px_0_rgba(17,24,39,0.04)]">
                    <div class="max-w-6xl mx-auto px-8 h-16 flex items-center gap-4">
                        <div class="min-w-0 flex-1">
                            <h1 class="text-lg font-semibold text-gray-900 truncate">{{ $title }}</h1>
                            @if($subtitle)<p class="text-xs text-gray-500 truncate">{{ $subtitle }}</p>@endif
                        </div>
                        @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
                    </div>
                </div>
            @endif

            <main class="max-w-3xl lg:max-w-6xl mx-auto px-4 lg:px-8 pt-5 lg:pt-8 pb-8 lg:pb-10">
                @include('tenant-portal._flash')
                {{ $slot }}
            </main>

            <footer class="max-w-3xl lg:max-w-6xl mx-auto px-4 lg:px-8 pb-24 lg:pb-8">
                <div class="border-t border-gray-200 pt-5 flex flex-col items-center gap-2 text-center lg:flex-row lg:justify-between lg:text-left text-xs text-gray-400">
                    <div class="flex items-center gap-2">
                        <x-brand :wordmark="false" size="xs" />
                        <span>{{ $copyright }}</span>
                    </div>
                    <p>Payments are collected securely by {{ config('app.name', 'Primora') }} on behalf of your landlord.</p>
                </div>
            </footer>
        </div>

        {{-- Bottom tabs (phones & tablets) --}}
        <nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-gray-200 pb-[env(safe-area-inset-bottom)]">
            <div class="grid grid-cols-4">
                @foreach($tabs as $item)
                    <a href="{{ route($item[0]) }}" class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium {{ $isActive($item) ? 'text-orange-600' : 'text-gray-500' }}">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $icons[$item[2]] }}"/></svg>
                        {{ $item[1] }}
                    </a>
                @endforeach
            </div>
        </nav>
    @endif
</body>
</html>
