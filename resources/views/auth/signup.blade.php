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
            {{-- Phone is typed without the +256, which the field shows. --}}
            phone: @js(preg_replace('/^\+?256/', '', (string) old('phone', ''))),
            password: '',
            password_confirmation: '',
            touched: {},
            orgPattern: new RegExp(@js(\App\Rules\OrganisationName::PATTERN), 'u'),
            personPattern: new RegExp(@js(\App\Rules\PersonName::PATTERN), 'u'),
            {{-- Shown once a field has been left, or Continue was pressed. --}}
            fieldError(field) { return this.touched[field] ? this.errors[field] : ''; },
            get phoneDigits() {
                let d = this.phone.replace(/\D/g, '');
                if (d.startsWith('256') && d.length === 12) d = d.slice(3);
                if (d.startsWith('0')) d = d.slice(1);
                return d;
            },
            get errors() {
                const company = this.company_name.trim(), name = this.name.trim(), email = this.email.trim();
                return {
                    company_name: !company ? 'Enter your company or landlord name.'
                        : !this.orgPattern.test(company) ? 'Use letters (numbers are fine too), e.g. Kampala Properties Ltd.' : '',
                    name: !name ? 'Enter your name.'
                        : !this.personPattern.test(name) ? 'Use letters only, e.g. Grace Namata.' : '',
                    email: !email ? 'Enter your email address.'
                        : !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email) ? 'Enter a valid email address, e.g. you@example.com.' : '',
                    phone: !this.phone.trim() ? 'Enter your phone number.'
                        : !/^[347]\d{8}$/.test(this.phoneDigits) ? 'Enter the 9 digits after +256, e.g. 772 123456.' : '',
                };
            },
            {{-- The same rules as Password::defaults() on the server. --}}
            get passwordRules() {
                const p = this.password;
                return [
                    { label: 'At least 8 characters', ok: p.length >= 8 },
                    { label: 'A letter', ok: /\p{L}/u.test(p) },
                    { label: 'A number', ok: /\p{N}/u.test(p) },
                    { label: 'A symbol, e.g. ! @ # - _', ok: /[\p{Z}\p{S}\p{P}]/u.test(p) },
                ];
            },
            get step1Valid() { return !this.errors.company_name; },
            get step2Valid() { return !this.errors.name && !this.errors.email && !this.errors.phone; },
            get step3Valid() { return this.passwordRules.every(r => r.ok) && this.password === this.password_confirmation; },
            next() {
                if (this.step === 1) {
                    this.touched.company_name = true;
                    if (this.step1Valid) this.step = 2;
                } else if (this.step === 2) {
                    Object.assign(this.touched, { name: true, email: true, phone: true });
                    if (this.step2Valid) this.step = 3;
                }
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

            <h2 class="text-lg font-semibold text-gray-900 mb-6" x-show="step === 1">Tell us about your organization</h2>
            <h2 class="text-lg font-semibold text-gray-900 mb-1" x-show="step === 2" x-cloak>Set up your account</h2>
            <h2 class="text-lg font-semibold text-gray-900 mb-6" x-show="step === 3" x-cloak>Secure your account</h2>
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
                        <input type="text" name="company_name" x-model="company_name" required autofocus maxlength="255"
                            @keydown.enter.prevent="next()" @blur="touched.company_name = company_name.trim() !== ''"
                            class="w-full rounded-xl border bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:ring-1 transition" :class="fieldError('company_name') ? 'border-red-400 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-orange-500 focus:ring-orange-500'"
                            placeholder="e.g. Kampala Properties Ltd" />
                        <p class="mt-1.5 text-xs text-red-600" x-show="fieldError('company_name')" x-text="fieldError('company_name')" x-cloak></p>
                    </div>

                    <button type="button" @click="next()"
                        class="w-full bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition">
                        Continue
                    </button>
                </div>

                {{-- Step 2: Account details --}}
                <div x-show="step === 2" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-x-2" x-transition:enter-end="opacity-100 translate-x-0" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Your name</label>
                        <input type="text" name="name" x-model="name" autocomplete="name" maxlength="255"
                            @blur="touched.name = name.trim() !== ''"
                            class="w-full rounded-xl border bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:ring-1 transition" :class="fieldError('name') ? 'border-red-400 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-orange-500 focus:ring-orange-500'"
                            placeholder="Enter your full name" />
                        <p class="mt-1.5 text-xs text-red-600" x-show="fieldError('name')" x-text="fieldError('name')" x-cloak></p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Email</label>
                        <input type="email" name="email" x-model="email" autocomplete="email" maxlength="255"
                            @blur="touched.email = email.trim() !== ''"
                            class="w-full rounded-xl border bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:ring-1 transition" :class="fieldError('email') ? 'border-red-400 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-orange-500 focus:ring-orange-500'"
                            placeholder="you@example.com" />
                        <p class="mt-1.5 text-xs text-red-600" x-show="fieldError('email')" x-text="fieldError('email')" x-cloak></p>
                        <p class="mt-1.5 text-xs text-gray-500" x-show="!fieldError('email')">You'll use this to log in, and we'll send a verification link here.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Phone</label>
                        {{-- Uganda only for now: +256 is fixed, the person types the rest. --}}
                        <div class="flex rounded-xl border bg-gray-50 focus-within:bg-gray-100 focus-within:ring-1 transition"
                            :class="fieldError('phone') ? 'border-red-400 focus-within:border-red-500 focus-within:ring-red-500' : 'border-gray-200 focus-within:border-orange-500 focus-within:ring-orange-500'">
                            <span class="flex items-center pl-4 pr-3 text-sm font-medium text-gray-700 border-r border-gray-200 select-none">+256</span>
                            <input type="tel" x-model="phone" inputmode="numeric" autocomplete="tel-national" maxlength="13"
                                @keydown.enter.prevent="next()" @blur="touched.phone = phone.trim() !== ''"
                                class="flex-1 min-w-0 border-0 bg-transparent px-3 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:ring-0 focus:outline-none"
                                placeholder="772 123456" />
                        </div>
                        <input type="hidden" name="phone" :value="phone.trim() ? '+256' + phoneDigits : ''" />
                        <p class="mt-1.5 text-xs text-red-600" x-show="fieldError('phone')" x-text="fieldError('phone')" x-cloak></p>
                    </div>

                    <div class="flex gap-3">
                        <button type="button" @click="back()"
                            class="flex-1 bg-gray-100 text-gray-700 hover:bg-gray-100 hover:text-gray-900 focus:ring-2 focus:ring-gray-300 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition">
                            Back
                        </button>
                        <button type="button" @click="next()"
                            class="flex-1 bg-orange-500 text-white hover:bg-orange-600 focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 focus:ring-offset-white rounded-xl px-4 py-2.5 text-sm font-medium transition">
                            Continue
                        </button>
                    </div>
                </div>

                {{-- Step 3: Security --}}
                <div x-show="step === 3" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-x-2" x-transition:enter-end="opacity-100 translate-x-0" class="space-y-4">
                    <div x-data="{ show: false }">
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Password</label>
                        <div class="relative">
                            <input :type="show ? 'text' : 'password'" name="password" x-model="password" autocomplete="new-password"
                                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 pr-10 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition"
                                placeholder="Create a password" />
                            <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-500 hover:text-orange-500 transition focus:outline-none">
                                <svg x-show="!show" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                <svg x-show="show" x-cloak class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" /></svg>
                            </button>
                        </div>
                        <ul class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                            <template x-for="rule in passwordRules" :key="rule.label">
                                <li class="flex items-center gap-1.5" :class="rule.ok ? 'text-green-600' : 'text-gray-500'">
                                    <svg x-show="rule.ok" class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
                                    <span x-show="!rule.ok" class="w-3.5 h-3.5 shrink-0 flex items-center justify-center"><span class="w-1.5 h-1.5 rounded-full bg-current"></span></span>
                                    <span x-text="rule.label"></span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1.5">Confirm password</label>
                        <input type="password" name="password_confirmation" x-model="password_confirmation" autocomplete="new-password"
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
