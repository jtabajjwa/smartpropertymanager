<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Primora') }}</title>

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
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <svg class="w-7 h-7" viewBox="0 0 120 120" fill="none">
                    <path d="M15 55 L60 15 L105 55" stroke="#1c2230" stroke-width="13" stroke-linecap="square" stroke-linejoin="miter"/>
                    <path d="M35 72 L60 50 L85 72" stroke="#c1704a" stroke-width="12" stroke-linecap="square" stroke-linejoin="miter"/>
                    <rect x="53" y="82" width="14" height="20" rx="1" fill="#1c2230"/>
                </svg>
                <span class="font-bold text-lg tracking-tight text-navy-800">{{ config('app.name', 'Primora') }}</span>
            </a>
            <button @click="sidebarOpen = false" title="Hide menu" class="text-gray-600 hover:text-gray-900">
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
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Property</p>
                <x-sidebar-link route="locations.index" label="Locations" icon="map-pin" />
                <x-sidebar-link route="estates.index" label="Estates" icon="building" />
                <x-sidebar-link route="units.index" label="Units" icon="cube" />
            </div>

            <!-- Tenancy -->
            <div>
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Tenancy</p>
                <x-sidebar-link route="tenants.index" label="Tenants" icon="users" />
                <x-sidebar-link route="customers.index" label="Customers" icon="user" />
                <x-sidebar-link route="leases.index" label="Leases" icon="document" />
            </div>

            <!-- Finance -->
            <div>
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Finance</p>
                <x-sidebar-link route="invoices.index" label="Invoices" icon="receipt" />
                <x-sidebar-link route="payments.index" label="Payments" icon="banknotes" />
                <x-sidebar-link route="security-deposit-ledgers.index" label="Security Deposits" icon="shield" />
                <x-sidebar-link route="utilities.index" label="Utilities" icon="bolt" />
            </div>

            <!-- Operations -->
            <div>
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Operations</p>
                <x-sidebar-link route="expenses.index" label="Expenses" icon="calculator" />
                <x-sidebar-link route="expense-items.index" label="Expense Items" icon="tag" />
                <x-sidebar-link route="vendors.index" label="Vendors" icon="wrench" />
                <x-sidebar-link route="vendor-jobs.index" label="Job Cards" icon="clipboard" />
                <x-sidebar-link route="issues.index" label="Tenant Issues" icon="chat" />
            </div>

            <!-- Templates -->
            <div>
                <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Templates</p>
                <x-sidebar-link route="lease-templates.index" label="Lease Templates" icon="clipboard" />
                @if(auth()->user()->isLandlordAdmin())
                    <x-sidebar-link route="company-profile.edit" label="Company Profile" icon="building" />
                    <x-sidebar-link route="subscription.show" label="Subscription" icon="banknotes" />
                @endif
            </div>

            <!-- Admin -->
            @if(auth()->user()->isLandlordAdmin())
                <div>
                    <p class="px-3 mb-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Admin</p>
                    <x-sidebar-link route="users.index" label="Users" icon="users" />
                    <x-sidebar-link route="audits.index" label="Audit Log" icon="clipboard" />
                    <x-sidebar-link route="sms-credits.show" label="SMS Credits" icon="chat" />
                    <x-sidebar-link route="sms-logs.index" label="SMS Logs" icon="chat" />
                </div>
            @endif
        </nav>
    </aside>

    <!-- Main Content -->
    <div class="lg:flex-1 lg:min-w-0">
        @php $actingFor = \App\Services\StaffActingSession::landlord(); @endphp

        @if ($actingFor)
            <!-- Staff mode banner -->
            <div class="bg-navy-800 text-white">
                <div class="px-4 sm:px-6 py-2.5 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <span class="inline-flex items-center gap-2 rounded-full bg-orange-500 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide">Staff mode</span>
                    <span class="text-gray-200">
                        You're working in <strong class="text-white">{{ $actingFor->name }}</strong>'s portal as {{ auth()->user()->name }}. Changes are recorded under your name.
                    </span>
                    <form method="POST" action="{{ route('staff.portal.exit') }}" class="ml-auto">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-white/20 px-3 py-1 text-xs font-medium text-white hover:bg-white/10 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                            Back to staff portal
                        </button>
                    </form>
                </div>
            </div>
        @endif

        <!-- Top Bar -->
        <header class="sticky top-0 z-30 h-14 bg-white/80 backdrop-blur-md border-b border-gray-200 flex items-center px-4 sm:px-6">
            <button @click="sidebarOpen = !sidebarOpen" title="Show/hide menu" class="p-2 -ml-2 rounded-xl text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            </button>
            @if (isset($header))
                <div class="ml-3 text-sm font-semibold text-gray-900">{{ $header }}</div>
            @endif

            <div class="ml-auto flex items-center gap-3">
                @if ($actingFor)
                    <span class="hidden sm:inline text-sm text-gray-600">{{ auth()->user()->name }} <span class="text-orange-600">(staff)</span></span>
                    <form method="POST" action="{{ route('staff.portal.exit') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 transition" title="Leave this landlord's portal">
                            Exit staff mode
                        </button>
                    </form>
                @else
                <span class="text-sm text-gray-600">{{ auth()->user()->name }}</span>
                <a href="{{ route('change-password') }}" class="p-2 rounded-xl text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition" title="Change password">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="p-2 rounded-xl text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition" title="Sign out">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3-3h-9m9 0l-3-3m3 3l-3 3"/></svg>
                    </button>
                </form>
                @endif
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

        <!-- Low / no SMS credit (admins only; not on the SMS Credits page itself) -->
        @if (auth()->user()->isLandlordAdmin() && ! request()->routeIs('sms-credits.*') && \App\Services\CurrentLandlord::id())
            @php
                $smsBalance = (int) \App\Models\Landlord::whereKey(\App\Services\CurrentLandlord::id())->value('sms_balance');
                $smsLow = $smsBalance <= app(\App\Services\SmsCredits::class)->lowBalanceThreshold();
            @endphp
            @if ($smsLow)
                <div class="{{ $smsBalance <= 0 ? 'bg-red-50 border-red-200 text-red-800' : 'bg-orange-50 border-orange-200 text-orange-800' }} border-b">
                    <div class="px-4 sm:px-6 py-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span>
                            @if ($smsBalance <= 0)
                                <strong>You're out of SMS credit.</strong> Tenants are not receiving SMS reminders, invoices or receipts.
                            @else
                                <strong>SMS credit is low:</strong> {{ number_format($smsBalance) }} SMS left.
                            @endif
                        </span>
                        <a href="{{ route('sms-credits.show') }}" class="ml-auto font-semibold underline underline-offset-2 hover:no-underline">Buy SMS</a>
                    </div>
                </div>
            @endif
        @endif

        <!-- Page Content -->
        <main class="p-4 sm:p-6">
            {{ $slot }}
        </main>
    </div>

    <x-confirm-modal />

</body>
</html>
