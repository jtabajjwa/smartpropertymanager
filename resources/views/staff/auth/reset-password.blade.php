<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Reset Password — Staff Portal — {{ config('app.name', 'Primora') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-sm">
        {{-- Logo --}}
        <div class="mb-8 flex justify-center">
            <x-logo />
        </div>

        {{-- Card --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xl p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-2">Reset Password</h2>
            <p class="text-sm text-gray-500 mb-6">Enter the 6-digit code sent to your email and choose a new password.</p>

            @if (session('success'))
                <div class="mb-4 rounded-xl bg-green-500/10 border border-green-500/20 px-4 py-3 text-sm text-green-600">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 rounded-xl bg-red-500/10 border border-red-500/20 px-4 py-3 text-sm text-red-600">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 rounded-xl bg-red-500/10 border border-red-500/20 px-4 py-3">
                    <ul class="text-sm text-red-600 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('staff.reset-password') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1.5">Email Address</label>
                    <input type="email" name="email" value="{{ request('email', old('email')) }}" required
                        class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                        placeholder="you@example.com" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1.5">Reset Code</label>
                    <input type="text" name="code" value="{{ old('code') }}" required autofocus maxlength="6"
                        class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition tracking-[0.3em] text-center text-lg"
                        placeholder="000000" />
                </div>

                <div x-data="{ show: false }">
                    <label class="block text-sm font-medium text-gray-600 mb-1.5">New Password</label>
                    <div class="relative">
                        <input :type="show ? 'text' : 'password'" name="password" required
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 pr-10 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                            placeholder="Minimum 8 characters" />
                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-orange-500 transition focus:outline-none">
                            <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                            <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                        </button>
                    </div>
                </div>

                <div x-data="{ show: false }">
                    <label class="block text-sm font-medium text-gray-600 mb-1.5">Confirm Password</label>
                    <div class="relative">
                        <input :type="show ? 'text' : 'password'" name="password_confirmation" required
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 pr-10 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                            placeholder="Repeat your new password" />
                        <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-orange-500 transition focus:outline-none">
                            <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                            <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition">
                    Reset Password
                </button>
            </form>

            <div class="mt-4 text-center">
                <a href="{{ route('staff.login') }}" class="text-sm text-gray-500 hover:text-gray-700 transition">Back to login</a>
            </div>
        </div>
    </div>

</body>
</html>
