<x-partner-layout title="Reset password" :guest="true">
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
        <h1 class="text-xl font-bold text-gray-900">Choose a new password</h1>
        <form method="POST" action="{{ route('partner.reset-password') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <x-label :required="true">Email</x-label>
                <x-input type="email" name="email" value="{{ old('email', request('email')) }}" required />
            </div>
            <div>
                <x-label :required="true">Code from the email</x-label>
                <x-input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required />
                @error('code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <x-label :required="true">New password</x-label>
                <x-input type="password" name="password" autocomplete="new-password" required />
                @error('password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <x-label :required="true">Confirm new password</x-label>
                <x-input type="password" name="password_confirmation" autocomplete="new-password" required />
            </div>
            <x-button type="submit" class="w-full justify-center">Reset password</x-button>
        </form>
    </div>
</x-partner-layout>
