<x-app-layout>
    <x-slot name="header">{{ \App\Support\PlanFeatures::name($feature) }}</x-slot>

    @php
        [$name, $gives] = \App\Support\PlanFeatures::FEATURES[$feature];
        $isAdmin = auth()->user()?->isLandlordAdmin();
        $current = $landlord?->plan;
    @endphp

    <div class="max-w-2xl mx-auto py-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-sm text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-orange-500/10 text-orange-600">
                <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
            </div>
            <h1 class="mt-5 text-xl font-bold text-gray-900">{{ $name }} isn't in your plan</h1>
            <p class="mt-2 text-sm text-gray-600">{{ $gives }}</p>
            @if($current)
                <p class="mt-1 text-xs text-gray-400">You're on the {{ $current->name }} plan.</p>
            @endif

            @if($plans->isNotEmpty())
                <div class="mt-6 rounded-xl bg-gray-50 px-5 py-4 text-left">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Included in</p>
                    <ul class="mt-2 space-y-1.5">
                        @foreach($plans as $plan)
                            <li class="flex items-center gap-2 text-sm text-gray-800">
                                <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                {{ $plan->name }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                @if($isAdmin)
                    <a href="{{ route('subscription.show') }}" class="inline-flex items-center rounded-xl bg-orange-500 px-5 py-2.5 text-sm font-medium text-white hover:bg-orange-600 transition">Change plan</a>
                @else
                    <p class="text-sm text-gray-500">Ask an administrator on your account to change your plan.</p>
                @endif
                <a href="{{ route('dashboard') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">Back to dashboard</a>
            </div>
            <p class="mt-6 text-xs text-gray-400">Questions about plans? Contact {{ config('app.name') }} and we'll help you choose.</p>
        </div>
    </div>
</x-app-layout>
