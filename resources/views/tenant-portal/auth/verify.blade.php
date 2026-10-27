<x-tenant-layout title="Enter your code" :guest="true">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
        <h1 class="text-xl font-bold text-gray-900">Enter your code</h1>
        <p class="mt-1 text-sm text-gray-500">We sent a 6-digit code by {{ $channel === 'email' ? 'email' : 'SMS' }} to <strong class="text-gray-800">{{ $masked }}</strong>. It expires in {{ \App\Services\TenantPortal\TenantLogin::CODE_MINUTES }} minutes.</p>

        <form method="POST" action="{{ route('tenant.verify') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <x-label :required="true">Code</x-label>
                <x-input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9 ]*" placeholder="••••••" class="text-center text-2xl tracking-[0.5em] font-semibold" autofocus required />
                @error('code')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <x-button type="submit" class="w-full justify-center">Sign in</x-button>
        </form>

        <div class="mt-5 flex items-center justify-between text-sm">
            <a href="{{ route('tenant.login') }}" class="text-gray-500 hover:text-gray-900">← Use a different {{ $channel === 'email' ? 'email' : 'number' }}</a>
            <form method="POST" action="{{ route('tenant.verify.resend') }}">
                @csrf
                <button type="submit" class="font-medium text-orange-600 hover:text-orange-700">Send a new code</button>
            </form>
        </div>
    </div>
</x-tenant-layout>
