<x-tenant-layout title="Choose a tenancy" :guest="true">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
        <h1 class="text-xl font-bold text-gray-900">Choose a tenancy</h1>
        <p class="mt-1 text-sm text-gray-500">Your phone number is on more than one tenancy. Which one do you want to open?</p>

        <form method="POST" action="{{ route('tenant.accounts') }}" class="mt-6 space-y-2">
            @csrf
            @foreach($tenants as $t)
                @php $lease = $t->leases->firstWhere('status', 'active') ?? $t->leases->first(); @endphp
                <button type="submit" name="tenant_id" value="{{ $t->id }}" class="w-full flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-left transition {{ $current === $t->id ? 'border-orange-500 bg-orange-50/40' : 'border-gray-200 hover:border-orange-500' }}">
                    <span>
                        <span class="block text-sm font-semibold text-gray-900">{{ $t->landlord?->name }}</span>
                        <span class="block text-xs text-gray-500">{{ $t->name }}@if($lease?->unit) · Unit {{ $lease->unit->name }}@endif</span>
                    </span>
                    <span class="text-orange-600">→</span>
                </button>
            @endforeach
        </form>
    </div>
</x-tenant-layout>
