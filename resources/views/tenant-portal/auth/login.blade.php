<x-tenant-layout title="Sign in" :guest="true">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
        <h1 class="text-xl font-bold text-gray-900">Sign in</h1>
        <p class="mt-1 text-sm text-gray-500">See your invoices, pay rent and report issues to your landlord.</p>

        <form method="POST" action="{{ route('tenant.login') }}" class="mt-6 space-y-4" x-data="{ busy: false }" @submit="busy = true">
            @csrf
            <div>
                <x-label :required="true">Phone number or unit number</x-label>
                <x-input name="identifier" value="{{ old('identifier') }}" placeholder="e.g. 0772 123 456 or A4" autocomplete="tel" autofocus required />
                @error('identifier')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="mt-1.5 text-xs text-gray-500">We'll send a one-time code by SMS to the phone number your landlord has for you.</p>
            </div>
            <x-button type="submit" class="w-full justify-center" x-bind:disabled="busy">
                <span x-show="!busy">Send me a code</span>
                <span x-show="busy" x-cloak>Sending…</span>
            </x-button>
        </form>
    </div>
</x-tenant-layout>
