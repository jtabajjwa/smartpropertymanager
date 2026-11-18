<x-partner-layout title="Change password">
    <div class="max-w-md bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
        <form method="POST" action="{{ route('partner.password.update') }}" class="space-y-4">
            @csrf
            <div>
                <x-label :required="true">Current password</x-label>
                <x-input type="password" name="current_password" autocomplete="current-password" required />
                @error('current_password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
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
            <x-button type="submit">Change password</x-button>
        </form>
    </div>
</x-partner-layout>
