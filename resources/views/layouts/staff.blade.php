<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Staff Portal — {{ config('app.name', 'Primora') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.store('confirm', {
                show: false,
                message: '',
                form: null,
                open(message, form) {
                    this.message = message;
                    this.form = form;
                    this.show = true;
                },
                close() {
                    this.show = false;
                    this.message = '';
                    this.form = null;
                },
                proceed() {
                    if (this.form) this.form.submit();
                    this.close();
                }
            });
        });
    </script>
</head>
<body class="font-sans antialiased bg-gray-50 lg:flex" x-data="{ sidebarOpen: true }" x-on:keydown.escape.window="sidebarOpen = false">

    <!-- Sidebar Backdrop (mobile only — desktop sidebar collapses in place, no overlay needed) -->
    <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm z-40 lg:hidden" @click="sidebarOpen = false" style="display: none;"></div>

    <!-- Sidebar: overlay drawer on mobile, collapsible panel that reflows content on lg: up -->
    <aside x-show="sidebarOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-x-4" x-transition:enter-end="opacity-100 translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-x-0" x-transition:leave-end="opacity-0 -translate-x-4" class="fixed inset-y-0 left-0 w-64 bg-white border-r border-gray-200 z-50 flex flex-col lg:static lg:shrink-0">

        <!-- Logo -->
        <div class="h-14 flex items-center justify-between px-5 border-b border-gray-200">
            <a href="{{ route('staff.dashboard') }}" class="flex items-center gap-2">
                <svg class="w-7 h-7" viewBox="0 0 120 120" fill="none">
                    <path d="M15 55 L60 15 L105 55" stroke="#1c2230" stroke-width="13" stroke-linecap="square" stroke-linejoin="miter"/>
                    <path d="M35 72 L60 50 L85 72" stroke="#c1704a" stroke-width="12" stroke-linecap="square" stroke-linejoin="miter"/>
                    <rect x="53" y="82" width="14" height="20" rx="1" fill="#1c2230"/>
                </svg>
                <span class="font-bold text-lg tracking-tight text-navy-800">{{ config('app.name', 'Primora') }} <span class="text-orange-500">Staff</span></span>
            </a>
            <button @click="sidebarOpen = false" title="Hide menu" class="text-gray-600 hover:text-gray-900">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-6">
            <div>
                <x-sidebar-link route="staff.dashboard" label="Dashboard" icon="home" />
            </div>

            <div>
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Accounts</p>
                @staffcan('landlords.view')<x-sidebar-link route="staff.landlords.index" label="Landlords" icon="building" />@endstaffcan
                @staffcan('plans.view')<x-sidebar-link route="staff.plans.index" label="Plans" icon="tag" />@endstaffcan
                @staffcan('subscriptions.view')<x-sidebar-link route="staff.subscriptions.index" label="Subscriptions" icon="clipboard" />@endstaffcan
                @staffcan('payments.view')<x-sidebar-link route="staff.payments.index" label="Payments" icon="banknotes" />@endstaffcan
                @staffcan('sms.view')<x-sidebar-link route="staff.sms.index" label="SMS" icon="chat" />@endstaffcan
            </div>

            @if(auth('staff')->user()->hasPermission('revenue.view') || auth('staff')->user()->hasPermission('collections.view'))
                <div>
                    <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Finance</p>
                    @staffcan('revenue.view')<x-sidebar-link route="staff.revenue.index" label="Revenue" icon="chart" />@endstaffcan
                    @staffcan('collections.view')<x-sidebar-link route="staff.collections.index" label="Rent Collections" icon="banknotes" />@endstaffcan
                </div>
            @endif

            @if(auth('staff')->user()->isAdmin())
                <div>
                    <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Admin</p>
                    <x-sidebar-link route="staff.staff-users.index" label="Staff Users" icon="users" />
                </div>
            @endif
        </nav>
    </aside>

    <!-- Main Content -->
    <div class="lg:flex-1 lg:min-w-0">
        <!-- Top Bar -->
        <header class="sticky top-0 z-30 h-14 bg-white/80 backdrop-blur-md border-b border-gray-200 flex items-center px-4 sm:px-6">
            <button @click="sidebarOpen = !sidebarOpen" title="Show/hide menu" class="p-2 -ml-2 rounded-xl text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            </button>
            @if (isset($header))
                <div class="ml-3 text-sm font-semibold text-gray-900">{{ $header }}</div>
            @endif

            <div class="ml-auto flex items-center gap-3">
                <span class="hidden sm:inline text-sm text-gray-600">{{ auth('staff')->user()->name }}</span>
                <a href="{{ route('staff.change-password') }}" class="p-2 rounded-xl text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition" title="Change password">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                </a>
                <form method="POST" action="{{ route('staff.logout') }}">
                    @csrf
                    <button type="submit" class="p-2 rounded-xl text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition" title="Sign out">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3-3h-9m9 0l-3-3m3 3l-3 3"/></svg>
                    </button>
                </form>
            </div>
        </header>

        <!-- Flash Messages -->
        @if (session('success'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-[-10px]" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed top-16 right-4 z-50 bg-white border border-gray-200 rounded-2xl shadow-lg px-5 py-3 flex items-center gap-3" style="display: none;">
                <svg class="w-5 h-5 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span class="text-sm text-gray-700">{{ session('success') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" x-transition class="fixed top-16 right-4 z-50 bg-white border border-red-500/30 rounded-2xl shadow-lg px-5 py-3 flex items-center gap-3" style="display: none;">
                <svg class="w-5 h-5 text-red-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span class="text-sm text-gray-700">{{ session('error') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div x-data="{ show: true }" x-show="show" x-transition class="fixed top-16 right-4 z-50 max-w-md bg-white border border-red-500/30 rounded-2xl shadow-lg px-5 py-4" style="display: none;">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-red-600 mb-1">validation errors:</p>
                        <ul class="text-xs text-gray-600 space-y-0.5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <button @click="show = false" class="text-gray-500 hover:text-gray-900 shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
        @endif

        <!-- Page Content -->
        <main class="p-4 sm:p-6">
            {{ $slot }}
        </main>
    </div>

    <x-confirm-modal />

</body>
</html>
