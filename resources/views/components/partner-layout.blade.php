@props(['title' => null, 'subtitle' => null, 'guest' => false])

@php
    $user = auth('partner')->user();
    $partner = $user?->partner;
    $icons = [
        'home' => 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'landlords' => 'M2.25 21h19.5M3.75 3v18m4.5-18v18m4.5-18v18m4.5-18v18M5.25 3h13.5M5.25 21h13.5M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15',
        'add' => 'M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'codes' => 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z M6 6h.008v.008H6V6z',
        'earnings' => 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z',
        'profile' => 'M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z',
    ];
    // [route, label, icon, also active on]
    $nav = [
        ['partner.dashboard', 'Home', 'home', []],
        ['partner.landlords', 'Landlords', 'landlords', []],
        ['partner.landlords.create', 'Sign up', 'add', []],
        ['partner.earnings', 'Earnings', 'earnings', []],
        ['partner.codes', 'Codes', 'codes', []],
        ['partner.profile', 'Profile', 'profile', ['partner.password', 'partner.agreement']],
    ];
    $isActive = fn (array $item) => request()->routeIs($item[0]) || collect($item[3])->contains(fn ($r) => request()->routeIs($r));
    $initials = collect(preg_split('/\s+/', trim((string) $user?->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
    $copyright = '© '.now()->year.' '.config('app.name', 'Primora').'. All rights reserved.';
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-shell" content="partner">
    <meta name="theme-color" content="#1c2230">
    <title>{{ $title ? $title.' · ' : '' }}Partner Portal · {{ config('app.name', 'Primora') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/tenant.js'])
</head>
<body class="app-bg {{ $guest ? '' : 'opaque-sidebar' }} font-sans antialiased text-gray-900 min-h-screen" hx-boost="true">
    <div id="app-progress" aria-hidden="true"></div>
    @if($guest)
        <main class="min-h-screen lg:grid lg:grid-cols-2">
            <div class="hidden lg:flex flex-col justify-between bg-navy-800 text-white p-12 relative overflow-hidden">
                <div class="flex items-center gap-4">
                    <x-brand :dark="true" />
                    <span class="rounded-md bg-orange-500/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-orange-300">Partner portal</span>
                </div>
                <div class="relative z-10 max-w-md">
                    <h2 class="text-4xl font-bold leading-tight">Grow with Primora. Earn as your landlords grow.</h2>
                    <ul class="mt-8 space-y-4 text-gray-300">
                        @foreach(['Sign landlords up in minutes, or share your code', 'Give landlords an offer with your partner code', 'See every landlord you brought in and how they are doing', 'Earn a share of their subscriptions'] as $point)
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
            </div>

            <div class="min-h-screen flex flex-col items-center justify-center px-4 py-10">
                <div class="mb-8 text-center lg:hidden">
                    <x-logo icon-size="w-12 h-12" wordmark-size="text-2xl" />
                    <span class="mt-3 inline-block rounded-md bg-orange-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-orange-700">Partner portal</span>
                </div>
                <div class="w-full {{ request()->routeIs('partner.apply') ? 'max-w-lg' : 'max-w-sm' }}">
                    @include('tenant-portal._flash')
                    {{ $slot }}
                </div>
                <p class="mt-10 text-[11px] text-gray-400 lg:hidden">{{ $copyright }}</p>
            </div>
        </main>
    @else
        {{-- Sidebar (desktop) --}}
        <aside class="hidden lg:flex fixed inset-y-0 left-0 z-30 w-64 flex-col bg-navy-800 text-white">
            <a href="{{ route('partner.dashboard') }}" class="h-16 px-5 flex items-center gap-3 border-b border-white/10" aria-label="{{ config('app.name', 'Primora') }} partner home">
                <x-brand :dark="true" />
                <span class="ml-auto rounded-md bg-orange-500/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-orange-300">Partner</span>
            </a>

            <div class="mx-3 mt-4 rounded-xl bg-white/5 px-3 py-3 leading-tight">
                <p class="text-[10px] uppercase tracking-wider text-gray-400">{{ $partner?->typeLabel() }}</p>
                <p class="text-sm font-semibold truncate">{{ $partner?->name }}</p>
                <p class="mt-1 text-xs text-gray-300">Agent no. <span class="font-mono font-semibold text-orange-300">{{ $partner?->number }}</span></p>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                @foreach($nav as $item)
                    <a href="{{ route($item[0]) }}" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition {{ $isActive($item) ? 'bg-white/10 text-white' : 'text-gray-300 hover:bg-white/5 hover:text-white' }}">
                        <svg class="w-5 h-5 {{ $isActive($item) ? 'text-orange-400' : 'text-gray-400 group-hover:text-gray-200' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="{{ $icons[$item[2]] }}"/></svg>
                        {{ $item[0] === 'partner.landlords.create' ? 'Sign up a landlord' : $item[1] }}
                    </a>
                @endforeach
            </nav>

            <div class="border-t border-white/10 p-4" x-data="{ open: false }">
                <div class="relative">
                    <button type="button" @click="open = !open" class="w-full flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-white/5 text-left">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-orange-500 text-sm font-semibold">{{ $initials ?: '?' }}</span>
                        <span class="min-w-0 flex-1 leading-tight">
                            <span class="block text-sm font-medium truncate">{{ $user?->name }}</span>
                            <span class="block text-xs text-gray-400 truncate">{{ $user?->email }}</span>
                        </span>
                    </button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute bottom-full left-0 right-0 mb-2 rounded-xl bg-white text-gray-700 shadow-lg ring-1 ring-gray-200 py-1 text-sm">
                        <a href="{{ route('partner.password') }}" class="block px-4 py-2 hover:bg-gray-50">Change password</a>
                        <form method="POST" action="{{ route('partner.logout') }}">
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
                <a href="{{ route('partner.dashboard') }}" class="shrink-0"><x-brand :dark="true" :wordmark="false" size="sm" /></a>
                <div class="min-w-0 flex-1 leading-tight">
                    <p class="text-sm font-semibold truncate">{{ $partner?->name }}</p>
                    <p class="text-xs text-gray-300 truncate">Agent no. {{ $partner?->number }}</p>
                </div>
                <a href="{{ route('partner.profile') }}" class="p-2 text-xs text-gray-300 hover:text-white">Profile</a>
                <form method="POST" action="{{ route('partner.logout') }}">
                    @csrf
                    <button type="submit" class="p-2 -mr-2 text-xs text-gray-300 hover:text-white">Sign out</button>
                </form>
            </div>
        </header>

        <div class="lg:pl-64">
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

            <main class="max-w-3xl lg:max-w-6xl mx-auto px-4 lg:px-8 pt-5 lg:pt-8 pb-24 lg:pb-10">
                @if($partner && $partner->status === 'pending')
                    <div class="mb-4 rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800">Your application is being reviewed. Once Primora approves it, you can sign landlords up and use your codes.</div>
                @elseif($partner && ! $partner->agreement_accepted_at && ! request()->routeIs('partner.agreement'))
                    <div class="mb-4 rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800">Please <a href="{{ route('partner.agreement') }}" class="font-semibold underline">read and accept the partner agreement</a> to start signing landlords up.</div>
                @endif
                @include('tenant-portal._flash')
                {{ $slot }}
            </main>
        </div>

        {{-- Bottom tabs (phones & tablets) --}}
        <nav class="lg:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-gray-200 pb-[env(safe-area-inset-bottom)]">
            <div class="grid grid-cols-5">
                @foreach(array_slice($nav, 0, 5) as $item)
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
