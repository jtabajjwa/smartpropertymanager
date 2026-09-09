<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign Up — {{ config('app.name', 'Primora') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-50 min-h-screen flex items-center justify-center p-4">

    @php
        $initialStep = 1;
        if ($errors->hasAny(['password', 'password_confirmation'])) {
            $initialStep = 3;
        } elseif ($errors->hasAny(['name', 'email', 'phone'])) {
            $initialStep = 2;
        } elseif ($errors->has('company_name')) {
            $initialStep = 1;
        }
    @endphp

    <div class="w-full max-w-sm"
        x-data="{
            step: {{ $initialStep }},
            company_name: @js(old('company_name', '')),
            name: @js(old('name', '')),
            email: @js(old('email', '')),
            phone: @js(old('phone', '')),
            password: '',
            password_confirmation: '',
            get step1Valid() { return this.company_name.trim().length > 0; },
            get step2Valid() { return this.name.trim().length > 0 && this.email.trim().length > 0 && this.phone.trim().length > 0; },
            get step3Valid() { return this.password.length >= 8 && this.password === this.password_confirmation; },
            next() {
                if (this.step === 1 && this.step1Valid) this.step = 2;
                else if (this.step === 2 && this.step2Valid) this.step = 3;
            },
            back() { if (this.step > 1) this.step--; }
        }">
        {{-- Logo --}}
        <div class="mb-8 flex justify-center">
            <x-logo />
        </div>

        {{-- Card --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-2xl p-6">
            {{-- Step progress --}}
            <div class="mb-6">
                <div class="flex items-center gap-2 mb-2">
                    <div class="flex-1 h-1.5 rounded-full bg-gray-100">
                        <div class="h-1.5 rounded-full bg-orange-500 transition-all duration-300" :class="step >= 1 ? 'w-full' : 'w-0'"></div>
                    </div>
                    <div class="flex-1 h-1.5 rounded-full bg-gray-100">
                        <div class="h-1.5 rounded-full bg-orange-500 transition-all duration-300" :class="step >= 2 ? 'w-full' : 'w-0'"></div>
                    </div>
                    <div class="flex-1 h-1.5 rounded-full bg-gray-100">
                        <div class="h-1.5 rounded-full bg-orange-500 transition-all duration-300" :class="step >= 3 ? 'w-full' : 'w-0'"></div>
                    </div>
                </div>
                <p class="text-xs font-medium text-gray-500">Step <span x-text="step"></span> of 3</p>
            </div>

            <h2 class="text-lg font-semibold text-gray-900 mb-1" x-show="step === 1">Tell us about your organization</h2>
            <h2 class="text-lg font-semibold text-gray-900 mb-1" x-show="step === 2" x-cloak>Set up your account</h2>
            <h2 class="text-lg font-semibold text-gray-900 mb-6" x-show="step === 3" x-cloak>Secure your account</h2>
            <p class="text-sm text-gray-500 mb-6" x-show="step === 1">This is the property management business landlords will see.</p>
            <p class="text-sm text-gray-500 mb-6" x-show="step === 2" x-cloak>This is how you'll log in and how we'll reach you.</p>

            {{-- Flash Messages --}}
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

            <form method="POST" action="{{ route('signup') }}">
                @csrf

                {{-- Step 1: Organization --}}
                <div x-show="step === 1" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-x-2" x-transition:enter-end="opacity-100 translate-x-0" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Company / Landlord name</label>
                        <input type="text" name="company_name" x-model="company_name" required autofocus
                            @keydown.enter.prevent="next()"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                            placeholder="e.g. Kampala Properties Ltd" />
                    </div>

                    <button type="button" @click="next()" :disabled="!step1Valid"
                        class="w-full bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed">
                        Continue
                    </button>
                </div>

                {{-- Step 2: Account details --}}
                <div x-show="step === 2" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-x-2" x-transition:enter-end="opacity-100 translate-x-0" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Your name</label>
                        <input type="text" name="name" x-model="name"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                            placeholder="Enter your full name" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Email</label>
                        <input type="email" name="email" x-model="email"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                            placeholder="you@example.com" />
                        <p class="mt-1.5 text-xs text-gray-500">You'll use this to log in, and we'll send a verification link here.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Phone</label>
                        <input type="text" name="phone" x-model="phone"
                            @keydown.enter.prevent="next()"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                            placeholder="e.g. 0700000000" />
                    </div>

                    <div class="flex gap-3">
                        <button type="button" @click="back()"
                            class="flex-1 bg-gray-100 text-gray-700 hover:bg-gray-100 hover:text-gray-900 focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition">
                            Back
                        </button>
                        <button type="button" @click="next()" :disabled="!step2Valid"
                            class="flex-1 bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed">
                            Continue
                        </button>
                    </div>
                </div>

                {{-- Step 3: Security --}}
                <div x-show="step === 3" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-x-2" x-transition:enter-end="opacity-100 translate-x-0" class="space-y-4">
                    <div x-data="{ show: false }">
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Password</label>
                        <div class="relative">
                            <input :type="show ? 'text' : 'password'" name="password" x-model="password"
                                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 pr-10 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                                placeholder="At least 8 characters" />
                            <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-orange-500 transition focus:outline-none">
                                <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                            </button>
                        </div>
                        <p class="mt-1.5 text-xs" :class="password.length > 0 && password.length < 8 ? 'text-red-600' : 'text-gray-500'">At least 8 characters.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Confirm password</label>
                        <input type="password" name="password_confirmation" x-model="password_confirmation"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                            placeholder="Re-enter your password" />
                        <p class="mt-1.5 text-xs text-red-600" x-show="password_confirmation.length > 0 && password !== password_confirmation" x-cloak>Passwords don't match.</p>
                    </div>

                    <div class="flex gap-3">
                        <button type="button" @click="back()"
                            class="flex-1 bg-gray-100 text-gray-700 hover:bg-gray-100 hover:text-gray-900 focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition">
                            Back
                        </button>
                        <button type="submit" :disabled="!step3Valid"
                            class="flex-1 bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition disabled:opacity-40 disabled:cursor-not-allowed">
                            Create account
                        </button>
                    </div>
                </div>
            </form>

            <p class="mt-6 text-center text-sm text-gray-500">
                Already have an account?
                <a href="{{ route('login') }}" class="text-orange-500 hover:text-orange-600 transition">Sign in</a>
            </p>
        </div>
    </div>

</body>
</html>
