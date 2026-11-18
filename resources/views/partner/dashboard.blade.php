<x-partner-layout title="Home" subtitle="The landlords you've brought to Primora">
    <div class="space-y-6">
        <h1 class="text-xl font-bold text-gray-900 lg:hidden">Hello, {{ \Illuminate\Support\Str::before(auth('partner')->user()->name, ' ') }}</h1>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([['Landlords', $total, 'All you brought in'], ['Paying', $paying, 'Subscription active'], ['On trial', $trial, 'Not paying yet'], ['Signed up this month', $thisMonth, now()->format('F')]] as [$label, $value, $hint])
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($value) }}</p>
                    <p class="text-xs text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900">Latest landlords</h2>
                    @if($partner->isActive() && $partner->agreement_accepted_at)<a href="{{ route('partner.landlords') }}" class="text-sm text-orange-600 hover:text-orange-700">All landlords</a>@endif
                </div>
                @forelse($recent as $l)
                    @php $state = $l->subscriptionState(); @endphp
                    <div class="px-5 py-3 flex items-center justify-between gap-3 text-sm {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 truncate">{{ $l->name }}</p>
                            <p class="text-xs text-gray-500">Joined {{ $l->partner_attributed_at?->format('d M Y') ?? $l->created_at->format('d M Y') }}</p>
                        </div>
                        <x-badge :color="['trial' => 'orange', 'active' => 'green', 'expiring' => 'yellow'][$state] ?? 'gray'">{{ ['trial' => 'Trial', 'trial_ended' => 'Trial ended', 'active' => 'Paying', 'expiring' => 'Renewal due', 'expired' => 'Lapsed', 'past_due' => 'Overdue', 'cancelled' => 'Cancelled'][$state] ?? ucfirst($state) }}</x-badge>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-gray-500">No landlords yet. Sign one up, or share your code.</p>
                @endforelse
            </section>

            <section class="bg-navy-800 text-white rounded-2xl p-5 space-y-3">
                <p class="text-xs uppercase tracking-wider text-gray-300">Your code</p>
                @if($code)
                    <p class="text-3xl font-bold tracking-wide">{{ $code->code }}</p>
                    <p class="text-sm text-gray-300">or agent number <strong class="text-white">{{ $partner->number }}</strong></p>
                    <p class="text-xs text-gray-400">Landlords enter it when they sign up, or use your link.</p>
                    @if($partner->isActive() && $partner->agreement_accepted_at)<a href="{{ route('partner.codes') }}" class="inline-block text-sm font-medium text-orange-300 hover:text-orange-200">Share your code →</a>@endif
                @else
                    <p class="text-sm text-gray-300">Your code is ready once Primora approves your account.</p>
                @endif
            </section>
        </div>

        @if($partner->isActive() && $partner->agreement_accepted_at)
            <a href="{{ route('partner.earnings') }}" class="block bg-white rounded-2xl border border-gray-200 shadow-sm p-5 hover:border-orange-300">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Your earnings</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900">UGX {{ number_format($balances['available']) }} <span class="text-sm font-medium text-gray-500">available</span></p>
                    </div>
                    <div class="flex gap-6 text-sm text-gray-600">
                        <span>On hold <strong class="block text-gray-900">UGX {{ number_format($balances['on_hold']) }}</strong></span>
                        <span>This month <strong class="block text-gray-900">UGX {{ number_format($earnedThisMonth) }}</strong></span>
                    </div>
                </div>
            </a>
        @endif
    </div>
</x-partner-layout>
