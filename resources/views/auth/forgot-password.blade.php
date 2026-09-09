<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Forgot Password — {{ config('app.name', 'Primora') }}</title>
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
            <h2 class="text-lg font-semibold text-gray-900 mb-2">Forgot Password</h2>
            <p class="text-sm text-gray-500 mb-6">Enter your phone number and we'll send you a reset code via SMS.</p>

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

            <form method="POST" action="{{ route('forgot-password') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1.5">Phone Number</label>
                    <input type="text" name="phone" value="{{ old('phone') }}" required autofocus
                        class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                        placeholder="0771234567" />
                </div>

                <button type="submit" class="w-full bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition">
                    Send Reset Code
                </button>
            </form>

            <div class="mt-4 text-center">
                <a href="{{ route('login') }}" class="text-sm text-gray-500 hover:text-gray-700 transition">Back to login</a>
            </div>
        </div>
    </div>

</body>
</html>
