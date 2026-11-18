<x-partner-layout title="Sign in" :guest="true">
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
        <h1 class="text-xl font-bold text-gray-900">Partner sign in</h1>
        <p class="mt-1 text-sm text-gray-500">For Primora marketing partners and agents.</p>

        <form method="POST" action="{{ route('partner.login') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <x-label :required="true">Email</x-label>
                <x-input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus />
                @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <x-label :required="true">Password</x-label>
                <x-input type="password" name="password" autocomplete="current-password" required />
            </div>
            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-gray-600"><input type="checkbox" name="remember" value="1" class="rounded border-gray-300 text-orange-600"> Keep me signed in</label>
                <a href="{{ route('partner.forgot-password') }}" class="text-orange-600 hover:text-orange-700">Forgot password?</a>
            </div>
            <x-button type="submit" class="w-full justify-center">Sign in</x-button>
        </form>
    </div>
    <p class="mt-6 text-center text-sm text-gray-500">Not a partner yet? <a href="{{ route('partner.apply') }}" class="font-medium text-orange-600 hover:text-orange-700">Apply to become one</a></p>
</x-partner-layout>
