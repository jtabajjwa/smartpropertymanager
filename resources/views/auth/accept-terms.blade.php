<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Terms of Service — {{ config('app.name', 'Primora') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md" x-data="{ terms: false }">
        <div class="mb-8 flex justify-center">
            <x-logo />
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xl p-6">
            @if ($firstTime)
                <h2 class="text-lg font-semibold text-gray-900 mb-2">Before you continue</h2>
                <p class="text-sm text-gray-500 mb-5">Please read and accept Primora’s Terms of Service and Privacy Policy for {{ $landlord->name }}. They cover your subscription, rent collection and payouts, and how your tenants’ data is protected.</p>
            @else
                <h2 class="text-lg font-semibold text-gray-900 mb-2">We’ve updated our terms</h2>
                <p class="text-sm text-gray-500 mb-5">Please read and accept the updated Terms of Service and Privacy Policy to keep using Primora for {{ $landlord->name }}.</p>
            @endif

            @if ($errors->any())
                <div class="mb-4 rounded-xl bg-red-500/10 border border-red-500/20 px-4 py-3 text-sm text-red-600">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="mb-5 flex flex-col sm:flex-row gap-2">
                <a href="{{ config('legal.terms_url') }}" target="_blank" rel="noopener" class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                    Read the Terms of Service
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
                <a href="{{ config('legal.privacy_url') }}" target="_blank" rel="noopener" class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                    Read the Privacy Policy
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
            </div>

            <form method="POST" action="{{ route('terms.accept') }}" class="space-y-5">
                @csrf
                <label class="flex items-start gap-3 text-sm text-gray-600 cursor-pointer">
                    <input type="checkbox" name="terms" value="1" x-model="terms"
                        class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-orange-500 focus:ring-orange-500" />
                    <span>I have read and agree to the Terms of Service and Privacy Policy on behalf of {{ $landlord->name }}.</span>
                </label>

                <button type="submit" :disabled="!terms"
                    class="w-full bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed">
                    Agree and continue
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
                @csrf
                <button type="submit" class="text-sm text-gray-500 hover:text-gray-700">Sign out</button>
            </form>
        </div>
    </div>

</body>
</html>
