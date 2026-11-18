<x-partner-layout title="Forgot password" :guest="true">
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
        <h1 class="text-xl font-bold text-gray-900">Reset your password</h1>
        <p class="mt-1 text-sm text-gray-500">We'll email you a 6-digit code.</p>
        <form method="POST" action="{{ route('partner.forgot-password') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <x-label :required="true">Email</x-label>
                <x-input type="email" name="email" value="{{ old('email') }}" required autofocus />
                @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <x-button type="submit" class="w-full justify-center">Send code</x-button>
        </form>
    </div>
    <p class="mt-6 text-center text-sm"><a href="{{ route('partner.login') }}" class="text-orange-600 hover:text-orange-700">Back to sign in</a></p>
</x-partner-layout>
