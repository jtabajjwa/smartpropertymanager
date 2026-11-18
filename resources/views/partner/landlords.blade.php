<x-partner-layout title="Landlords" subtitle="Everyone you've brought to Primora">
    <x-slot name="actions"><a href="{{ route('partner.landlords.create') }}"><x-button type="button">Sign up a landlord</x-button></a></x-slot>
    @php
        $labels = ['trial' => ['Trial', 'orange'], 'trial_ended' => ['Trial ended', 'gray'], 'active' => ['Paying', 'green'], 'expiring' => ['Renewal due', 'yellow'], 'expired' => ['Lapsed', 'gray'], 'past_due' => ['Overdue', 'red'], 'cancelled' => ['Cancelled', 'gray']];
    @endphp

    <div class="space-y-4">
        <form method="GET" class="flex gap-2">
            <x-input name="search" value="{{ $search }}" placeholder="Search name, email or phone" class="max-w-xs" />
            <x-button type="submit">Search</x-button>
        </form>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            @if($landlords->isEmpty())
                <p class="px-6 py-12 text-center text-sm text-gray-500">{{ $search ? 'No landlords match.' : 'No landlords yet.' }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-600">
                            <tr>
                                <th class="px-5 py-3 text-left font-semibold">Landlord</th>
                                <th class="px-5 py-3 text-left font-semibold">Contact</th>
                                <th class="px-5 py-3 text-left font-semibold">Subscription</th>
                                <th class="px-5 py-3 text-left font-semibold">Joined</th>
                                <th class="px-5 py-3 text-right font-semibold">You earned</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($landlords as $l)
                                @php [$label, $color] = $labels[$l->subscriptionState()] ?? [ucfirst($l->subscriptionState()), 'gray']; $admin = $l->users->first(); @endphp
                                <tr>
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-gray-900">{{ $l->name }}</p>
                                        @if($l->partnerCode)<p class="text-xs text-gray-500">Code {{ $l->partnerCode->code }} · {{ \App\Services\PartnerProgram::SOURCES[$l->partner_source] ?? '' }}</p>@endif
                                    </td>
                                    <td class="px-5 py-3 text-gray-700">
                                        @if($admin)<p>{{ $admin->name }}</p>@endif
                                        @if($l->contact_phone)<a href="tel:{{ preg_replace('/\s+/', '', $l->contact_phone) }}" class="block text-xs text-orange-600 hover:text-orange-700">{{ $l->contact_phone }}</a>@endif
                                        @if($l->contact_email)<a href="mailto:{{ $l->contact_email }}" class="block text-xs text-gray-500 hover:text-orange-600">{{ $l->contact_email }}</a>@endif
                                    </td>
                                    <td class="px-5 py-3">
                                        <x-badge :color="$color">{{ $label }}</x-badge>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ $l->plan?->name ?? 'No plan yet' }}
                                            @if($l->current_period_end) · {{ $l->subscription_status === 'trialing' ? 'trial ends' : 'renews' }} {{ $l->current_period_end->format('d M Y') }}@endif
                                        </p>
                                    </td>
                                    <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ ($l->partner_attributed_at ?? $l->created_at)->format('d M Y') }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap font-medium">{{ number_format((float) ($earned[$l->id] ?? 0)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($landlords->hasPages())<div>{{ $landlords->links() }}</div>@endif
        <p class="text-xs text-gray-500">Use landlords' contact details only to help them with Primora, as the partner agreement says.</p>
    </div>
</x-partner-layout>
