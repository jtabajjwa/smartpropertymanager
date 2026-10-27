<x-tenant-layout title="Choose your phone" :guest="true">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
        <h1 class="text-xl font-bold text-gray-900">Which one is yours?</h1>
        <p class="mt-1 text-sm text-gray-500">More than one tenant has a unit with that number. Choose your phone (or email) and we'll send the code to it.</p>

        <form method="POST" action="{{ route('tenant.login.choose') }}" class="mt-6 space-y-2">
            @csrf
            @foreach($choices as $i => $label)
                <button type="submit" name="choice" value="{{ $i }}" class="w-full flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3 text-left hover:border-orange-500 hover:bg-orange-50/40 transition">
                    <span class="text-sm font-medium text-gray-900">{{ $label }}</span>
                    <span class="text-orange-600">→</span>
                </button>
            @endforeach
        </form>

        <a href="{{ route('tenant.login') }}" class="mt-5 inline-block text-sm text-gray-500 hover:text-gray-900">← None of these: use my phone number or email instead</a>
    </div>
</x-tenant-layout>
