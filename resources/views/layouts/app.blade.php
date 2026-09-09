<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'PRIME') }}</title>

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
<body class="font-sans antialiased bg-black" x-data="{ sidebarOpen: false }" x-on:keydown.escape.window="sidebarOpen = false">

    <!-- Sidebar Backdrop -->
    <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40" @click="sidebarOpen = false" style="display: none;"></div>

    <!-- Sidebar -->
    <aside x-show="sidebarOpen" x-transition:enter="transition-transform ease-out duration-300" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition-transform ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full" class="fixed inset-y-0 left-0 w-64 bg-gray-950 border-r border-white/10 z-50 flex flex-col" style="display: none;">

        <!-- Logo -->
        <div class="h-14 flex items-center justify-between px-5 border-b border-white/10">
            <a href="{{ route('dashboard') }}" class="font-bold text-xl tracking-tight text-orange-500">PRIME</a>
            <button @click="sidebarOpen = false" class="text-gray-400 hover:text-white">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-6">
            <!-- Main -->
            <div>
                <x-sidebar-link route="dashboard" label="Dashboard" icon="home" />
            </div>

            <!-- Property -->
            <div>
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-600">Property</p>
                <x-sidebar-link route="locations.index" label="Locations" icon="map-pin" />
                <x-sidebar-link route="estates.index" label="Estates" icon="building" />
                <x-sidebar-link route="units.index" label="Units" icon="cube" />
            </div>

            <!-- Tenancy -->
            <div>
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-600">Tenancy</p>
                <x-sidebar-link route="tenants.index" label="Tenants" icon="users" />
                <x-sidebar-link route="customers.index" label="Customers" icon="user" />
                <x-sidebar-link route="leases.index" label="Leases" icon="document" />
            </div>

            <!-- Finance -->
            <div>
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-600">Finance</p>
                <x-sidebar-link route="invoices.index" label="Invoices" icon="receipt" />
                <x-sidebar-link route="payments.index" label="Payments" icon="banknotes" />
                <x-sidebar-link route="security-deposit-ledgers.index" label="Security Deposits" icon="shield" />
                <x-sidebar-link route="utilities.index" label="Utilities" icon="bolt" />
            </div>

            <!-- Operations -->
            <div>
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-600">Operations</p>
                <x-sidebar-link route="expenses.index" label="Expenses" icon="calculator" />
                <x-sidebar-link route="expense-items.index" label="Expense Items" icon="tag" />
            </div>

            <!-- Admin -->
            @if(auth()->user()->isAdmin())
                <div>
                    <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-600">Admin</p>
                    <x-sidebar-link route="users.index" label="Users" icon="users" />
                    <x-sidebar-link route="audits.index" label="Audit Log" icon="clipboard" />
                    <x-sidebar-link route="sms-logs.index" label="SMS Logs" icon="chat" />
                </div>
            @endif
        </nav>
    </aside>

    <!-- Main Content -->
    <div>
        <!-- Top Bar -->
        <header class="sticky top-0 z-30 h-14 bg-gray-950/80 backdrop-blur-md border-b border-white/10 flex items-center px-4 sm:px-6">
            <button @click="sidebarOpen = !sidebarOpen" class="p-2 -ml-2 rounded-xl text-gray-400 hover:text-white hover:bg-white/10 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            </button>
            @if (isset($header))
                <div class="ml-3 text-sm font-semibold text-white">{{ $header }}</div>
            @endif

            <div class="ml-auto flex items-center gap-3">
                <span class="text-sm text-gray-400">{{ auth()->user()->name }}</span>
                <a href="{{ route('change-password') }}" class="p-2 rounded-xl text-gray-400 hover:text-white hover:bg-white/10 transition" title="Change password">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="p-2 rounded-xl text-gray-400 hover:text-white hover:bg-white/10 transition" title="Sign out">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3-3h-9m9 0l-3-3m3 3l-3 3"/></svg>
                    </button>
                </form>
            </div>
        </header>

        <!-- Flash Messages -->
        @if (session('success'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-[-10px]" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed top-16 right-4 z-50 bg-gray-900 border border-white/10 rounded-2xl shadow-lg px-5 py-3 flex items-center gap-3" style="display: none;">
                <svg class="w-5 h-5 text-green-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span class="text-sm text-gray-300">{{ session('success') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" x-transition class="fixed top-16 right-4 z-50 bg-gray-900 border border-red-500/30 rounded-2xl shadow-lg px-5 py-3 flex items-center gap-3" style="display: none;">
                <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span class="text-sm text-gray-300">{{ session('error') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div x-data="{ show: true }" x-show="show" x-transition class="fixed top-16 right-4 z-50 max-w-md bg-gray-900 border border-red-500/30 rounded-2xl shadow-lg px-5 py-4" style="display: none;">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-red-400 mb-1">validation errors:</p>
                        <ul class="text-xs text-gray-400 space-y-0.5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <button @click="show = false" class="text-gray-500 hover:text-white shrink-0">
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
