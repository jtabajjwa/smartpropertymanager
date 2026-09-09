<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Verify Email — {{ config('app.name', 'Primora') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-sm">
        <div class="mb-8 flex justify-center">
            <x-logo />
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xl p-6 text-center">
            <div class="mx-auto mb-4 w-12 h-12 rounded-full bg-orange-500/10 flex items-center justify-center">
                <svg class="w-6 h-6 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
            </div>

            <h2 class="text-lg font-semibold text-gray-900 mb-2">Verify your email</h2>
            <p class="text-sm text-gray-600 mb-6">
                We've sent a verification link to <span class="text-gray-900">{{ auth()->user()->email }}</span>.
                Click the link to activate your account.
            </p>

            @if (session('success'))
                <div class="mb-4 rounded-xl bg-green-500/10 border border-green-500/20 px-4 py-3 text-sm text-green-600">
                    {{ session('success') }}
                </div>
            @endif

            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit" class="w-full bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition">
                    Resend verification email
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="w-full bg-gray-50 text-gray-600 hover:bg-gray-100 hover:text-gray-900 rounded-xl px-4 py-2.5 text-sm font-medium transition">
                    Log out
                </button>
            </form>
        </div>
    </div>

</body>
</html>
