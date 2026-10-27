<x-staff-layout>
    <x-slot name="header">Payout accounts</x-slot>

    <div class="max-w-7xl mx-auto space-y-6">
        @include('staff.liquidations._nav')

        <div class="flex flex-wrap gap-2">
            @foreach(\App\Models\PayoutAccount::STATUSES as $key => [$label])
                <a href="{{ route('staff.payout-accounts.index', ['status' => $key]) }}" class="rounded-xl border px-3 py-1.5 text-sm font-medium transition {{ $status === $key ? 'bg-navy-800 border-navy-800 text-white' : 'bg-white border-gray-200 text-gray-600 hover:border-orange-300' }}">
                    {{ $label }} <span class="ml-1 {{ $status === $key ? 'text-gray-300' : 'text-gray-400' }}">{{ $counts[$key] ?? 0 }}</span>
                </a>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            @forelse($accounts as $a)
                <div class="px-6 py-4 {{ ! $loop->last ? 'border-b border-gray-100' : '' }} flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between" x-data="{ rejecting: false }">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900">{{ $a->landlord?->name }}</p>
                        <p class="text-sm text-gray-700">{{ \App\Models\PayoutAccount::TYPES[$a->type] }} · {{ $a->summary() }}</p>
                        @if($a->type === 'bank')<p class="text-xs text-gray-500">{{ $a->bank_branch ? 'Branch '.$a->bank_branch : '' }}{{ $a->swift_code ? ' · SWIFT '.$a->swift_code : '' }}</p>@endif
                        <p class="mt-1 text-xs text-gray-400">Added {{ $a->created_at->format('d M Y, H:i') }} by {{ $a->createdBy?->name ?? '—' }}@if($a->reviewedBy) · {{ $a->statusLabel() }} by {{ $a->reviewedBy->name }} {{ $a->reviewed_at?->format('d M Y') }}@endif @if($a->review_note) · “{{ $a->review_note }}”@endif</p>
                    </div>
                    @if($a->status === 'pending')
                        @staffcan('liquidations.approve')
                            <div class="flex flex-wrap items-center gap-2 shrink-0">
                                <form method="POST" action="{{ route('staff.payout-accounts.verify', $a->id) }}" x-show="!rejecting" @submit.prevent="$store.confirm.open('Verify this account? Only do this once you have confirmed it belongs to the landlord.', $el)">
                                    @csrf
                                    <x-button type="submit">Verify</x-button>
                                </form>
                                <button type="button" x-show="!rejecting" @click="rejecting = true" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-medium text-red-600 hover:border-red-300">Reject…</button>
                                <form method="POST" action="{{ route('staff.payout-accounts.reject', $a->id) }}" x-show="rejecting" x-cloak class="flex gap-2">
                                    @csrf
                                    <x-input name="reason" placeholder="Reason for the landlord" required minlength="5" class="w-64" />
                                    <button type="submit" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white">Reject</button>
                                </form>
                            </div>
                        @endstaffcan
                    @elseif($a->type === 'bank' && $a->status === 'verified')
                        <div class="flex flex-col items-start gap-2 lg:items-end shrink-0" x-data="{ mode: null }">
                            @if($a->yo_bank_identifier)
                                <div class="flex items-center gap-2">
                                    <x-badge color="green">Verified</x-badge>
                                    <x-badge color="blue">Yo! registered</x-badge>
                                </div>
                                <span class="text-xs text-gray-500">Identifier <span class="font-mono">{{ $a->yo_bank_identifier }}</span></span>
                            @else
                                <div class="flex items-center gap-2">
                                    <x-badge color="green">Verified</x-badge>
                                    <x-badge color="orange">Not on Yo!</x-badge>
                                </div>
                                @staffcan('liquidations.approve')
                                    <div class="flex gap-3 text-sm" x-show="!mode">
                                        @if($canRegisterWithYo)<button type="button" class="font-medium text-orange-600 hover:text-orange-700" @click="mode = 'register'">Register with Yo!</button>@endif
                                        <button type="button" class="font-medium text-gray-600 hover:text-gray-900" @click="mode = 'identifier'">Enter Yo! identifier</button>
                                    </div>
                                    @php $d = $bankDefaults[mb_strtolower(trim((string) $a->bank_name))] ?? []; @endphp
                                    <form method="POST" action="{{ route('staff.payout-accounts.yo-register', $a->id) }}" x-show="mode === 'register'" x-cloak class="w-full lg:w-[28rem] space-y-2 rounded-xl border border-gray-200 bg-gray-50 p-4 text-left"
                                          @submit.prevent="$store.confirm.open('Register this account on Primora\u2019s Yo! account? Yo! treats it as verified: only do this for an account you have confirmed belongs to the landlord.', $el)">
                                        @csrf
                                        <p class="text-xs text-gray-600">Yo! needs the bank's legal name and address. {{ $d ? 'Filled in from an earlier registration at this bank.' : '' }}</p>
                                        <x-input name="name" value="{{ $d['name'] ?? '' }}" placeholder="Bank legal name, e.g. Stanbic Bank Uganda Limited" required />
                                        <div class="grid grid-cols-2 gap-2">
                                            <x-input name="address1" value="{{ $d['address1'] ?? '' }}" placeholder="Building" required />
                                            <x-input name="address2" value="{{ $d['address2'] ?? '' }}" placeholder="Street / plot" required />
                                            <x-input name="city" value="{{ $d['city'] ?? 'Kampala' }}" placeholder="City" required />
                                            <x-input name="country" value="{{ $d['country'] ?? 'Uganda' }}" placeholder="Country" required />
                                            <x-input name="state" value="{{ $d['state'] ?? '' }}" placeholder="State (optional)" />
                                            <x-input name="swift" value="{{ $d['swift'] ?? $a->swift_code }}" placeholder="SWIFT (optional)" />
                                        </div>
                                        <div class="flex justify-end gap-2 pt-1">
                                            <button type="button" class="px-3 py-1.5 text-sm text-gray-600" @click="mode = null">Cancel</button>
                                            <x-button type="submit">Register</x-button>
                                        </div>
                                    </form>
                                    <form method="POST" action="{{ route('staff.payout-accounts.yo-identifier', $a->id) }}" x-show="mode === 'identifier'" x-cloak class="w-full lg:w-[28rem] space-y-2 rounded-xl border border-gray-200 bg-gray-50 p-4 text-left">
                                        @csrf
                                        <p class="text-xs text-gray-600">Registered it in the Yo! portal (Account Information → Bank Account Information)? Enter its <strong>API Bank Identifier</strong>.</p>
                                        <x-input name="yo_bank_identifier" placeholder="API Bank Identifier" required />
                                        <div class="flex justify-end gap-2 pt-1">
                                            <button type="button" class="px-3 py-1.5 text-sm text-gray-600" @click="mode = null">Cancel</button>
                                            <x-button type="submit">Save</x-button>
                                        </div>
                                    </form>
                                @endstaffcan
                            @endif
                        </div>
                    @else
                        <x-badge :color="$a->statusColor()">{{ $a->statusLabel() }}</x-badge>
                    @endif
                </div>
            @empty
                <p class="px-6 py-12 text-center text-sm text-gray-500">No accounts here.</p>
            @endforelse
            @if($accounts->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">{{ $accounts->links() }}</div>
            @endif
        </div>
    </div>
</x-staff-layout>
