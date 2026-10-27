<x-staff-layout>
    <x-slot name="header">Payout {{ $l->reference }}</x-slot>

    @php
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $iApproved = $l->reviewed_by_staff_user_id === $staff->id;
        $d = $l->destination ?? [];
    @endphp

    <div class="max-w-6xl mx-auto space-y-6">
        <a href="{{ route('staff.liquidations.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900">← Payouts</a>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <div class="lg:col-span-2 space-y-6">
                {{-- Summary --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-mono text-sm text-gray-500">{{ $l->reference }}</p>
                            <h2 class="mt-1 text-xl font-bold text-gray-900">{{ $l->landlord?->name }}</h2>
                            <p class="text-sm text-gray-500">Requested {{ $l->created_at->format('d M Y, H:i') }} by {{ $l->requestedBy?->name ?? 'the landlord' }}</p>
                        </div>
                        <x-badge :color="$l->statusColor()">{{ $l->staffStatus() }}</x-badge>
                    </div>

                    <dl class="mt-6 grid grid-cols-3 gap-4">
                        <div class="rounded-xl bg-gray-50 p-4"><dt class="text-xs uppercase tracking-wide text-gray-500">Amount</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ $ugx($l->amount) }}</dd></div>
                        <div class="rounded-xl bg-gray-50 p-4"><dt class="text-xs uppercase tracking-wide text-gray-500">Transfer charge</dt><dd class="mt-1 text-lg font-semibold text-gray-900">{{ $ugx($l->fee) }}</dd></div>
                        <div class="rounded-xl bg-orange-50 p-4"><dt class="text-xs uppercase tracking-wide text-gray-500">To send</dt><dd class="mt-1 text-lg font-bold text-orange-700">{{ $ugx($l->net_amount) }}</dd></div>
                    </dl>

                    <div class="mt-6">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $l->isMobileMoney() ? 'Mobile money' : 'Bank account' }}</p>
                        <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            <div><dt class="text-gray-500">Name</dt><dd class="font-medium text-gray-900">{{ $d['account_name'] ?? '—' }}</dd></div>
                            @if($l->isMobileMoney())
                                <div><dt class="text-gray-500">Number</dt><dd class="font-mono font-medium text-gray-900">{{ $d['msisdn'] ?? '—' }}</dd></div>
                            @else
                                <div><dt class="text-gray-500">Bank / branch</dt><dd class="font-medium text-gray-900">{{ $d['bank_name'] ?? '—' }}{{ !empty($d['bank_branch']) ? ' · '.$d['bank_branch'] : '' }}</dd></div>
                                <div><dt class="text-gray-500">Account number</dt><dd class="font-mono font-medium text-gray-900">{{ $d['account_number'] ?? '—' }}</dd></div>
                                @if(!empty($d['swift_code']))<div><dt class="text-gray-500">SWIFT</dt><dd class="font-mono text-gray-900">{{ $d['swift_code'] }}</dd></div>@endif
                            @endif
                            @unless($l->isMobileMoney())
                                <div><dt class="text-gray-500">Yo! registration</dt><dd class="text-gray-900">{!! $account?->yo_bank_identifier ? '<span class="font-mono">'.e($account->yo_bank_identifier).'</span>' : '<span class="text-orange-700">Not registered</span>' !!}</dd></div>
                            @endunless
                            <div><dt class="text-gray-500">Account status now</dt><dd>@if($account)<x-badge :color="$account->statusColor()">{{ $account->statusLabel() }}</x-badge>@else — @endif</dd></div>
                        </dl>
                    </div>

                    @if($l->request_note)
                        <p class="mt-5 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-700"><span class="text-gray-500">Landlord's note:</span> {{ $l->request_note }}</p>
                    @endif
                </div>

                {{-- Trail --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-base font-semibold text-gray-900">History</h3>
                    <ol class="mt-4 space-y-3 text-sm">
                        <li class="flex gap-3"><span class="mt-1.5 h-2 w-2 rounded-full bg-gray-300 shrink-0"></span><span><strong>Requested</strong> {{ $l->created_at->format('d M Y, H:i') }} by {{ $l->requestedBy?->name ?? 'landlord' }}</span></li>
                        @if($l->reviewed_at)
                            <li class="flex gap-3"><span class="mt-1.5 h-2 w-2 rounded-full {{ $l->status === 'rejected' ? 'bg-red-500' : 'bg-blue-500' }} shrink-0"></span><span><strong>{{ $l->status === 'rejected' ? 'Rejected' : 'Approved' }}</strong> {{ $l->reviewed_at->format('d M Y, H:i') }} by {{ $l->reviewedBy?->name }}@if($l->review_note) · “{{ $l->review_note }}”@endif</span></li>
                        @endif
                        @if($l->executed_at)
                            <li class="flex gap-3"><span class="mt-1.5 h-2 w-2 rounded-full bg-orange-500 shrink-0"></span><span><strong>{{ $l->viaYo() ? 'Sent via Yo!' : 'Direct EFT recorded' }}</strong>@if($l->routeLabel()) <span class="text-gray-500">· {{ $l->routeLabel() }}</span>@endif {{ $l->executed_at->format('d M Y, H:i') }} by {{ $l->executedBy?->name }}@if($l->attempts > 1) · attempt {{ $l->attempts }}@endif @if($l->external_reference)<span class="font-mono text-xs text-gray-500"> · {{ $l->external_reference }}</span>@endif</span></li>
                        @endif
                        @if($l->status_message && in_array($l->status, ['processing', 'failed']))
                            <li class="flex gap-3"><span class="mt-1.5 h-2 w-2 rounded-full {{ $l->status === 'failed' ? 'bg-red-500' : 'bg-gray-300' }} shrink-0"></span><span class="{{ $l->status === 'failed' ? 'text-red-700' : 'text-gray-600' }}">{{ $l->status_message }}</span></li>
                        @endif
                        @if($l->completed_at)
                            <li class="flex gap-3"><span class="mt-1.5 h-2 w-2 rounded-full bg-green-500 shrink-0"></span><span><strong>Completed</strong> {{ $l->completed_at->format('d M Y, H:i') }}@if($l->network_reference) · network ref <span class="font-mono">{{ $l->network_reference }}</span>@endif @if($l->bank_reference) · bank ref <span class="font-mono">{{ $l->bank_reference }}</span> ({{ $l->transfer_date?->format('d M Y') }})@endif</span></li>
                        @endif
                    </ol>
                </div>

                @if($l->logs->isNotEmpty())
                    @staffcan('payments.view')
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                            <h3 class="px-6 py-4 border-b border-gray-100 text-base font-semibold text-gray-900">Gateway log</h3>
                            <div class="divide-y divide-gray-100">
                                @foreach($l->logs as $log)
                                    <details class="px-6 py-3 text-sm">
                                        <summary class="cursor-pointer flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-gray-900">{{ $log->action }}</span>
                                            <span class="text-gray-500">{{ $log->created_at->format('d M H:i:s') }}</span>
                                            <x-badge :color="$log->isSuccessfulHttp() ? 'green' : 'red'">{{ $log->response_status ?? 'no response' }}</x-badge>
                                            @if($log->note)<span class="text-xs text-gray-500">{{ $log->note }}</span>@endif
                                        </summary>
                                        <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs">{{ $log->prettyRequest() }}</pre>
                                        @if($log->error)<p class="mt-2 text-xs text-red-600">{{ $log->error }}</p>@endif
                                        @if($log->response_body)<pre class="mt-2 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs">{{ $log->response_body }}</pre>@endif
                                    </details>
                                @endforeach
                            </div>
                        </div>
                    @endstaffcan
                @endif
            </div>

            {{-- Actions --}}
            <div class="space-y-6 lg:sticky lg:top-20">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 text-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Landlord balance (books)</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ $ugx($owed) }}</p>
                    <p class="mt-1 text-gray-500">Available after other open requests: <strong class="{{ $availableExcludingThis < (float) $l->amount && in_array($l->status, ['pending', 'approved']) ? 'text-red-600' : 'text-gray-900' }}">{{ $ugx($availableExcludingThis) }}</strong></p>
                </div>

                @if($l->status === 'pending')
                    @staffcan('liquidations.approve')
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 space-y-4" x-data="{ rejecting: false }">
                            <h3 class="text-base font-semibold text-gray-900">Review</h3>
                            <p class="text-sm text-gray-600">Check the destination belongs to the landlord and the balance covers it. After you approve, someone else must execute it.</p>
                            <form method="POST" action="{{ route('staff.liquidations.approve', $l->id) }}" class="space-y-3" x-show="!rejecting">
                                @csrf
                                <x-input name="note" placeholder="Note (optional)" maxlength="300" />
                                <x-button type="submit" class="w-full justify-center">Approve</x-button>
                            </form>
                            <button type="button" x-show="!rejecting" @click="rejecting = true" class="w-full rounded-xl border border-gray-200 px-4 py-2 text-sm font-medium text-red-600 hover:border-red-300">Reject…</button>
                            <form method="POST" action="{{ route('staff.liquidations.reject', $l->id) }}" class="space-y-3" x-show="rejecting" x-cloak>
                                @csrf
                                <x-textarea name="reason" rows="3" placeholder="Reason, shown to the landlord" required minlength="5"></x-textarea>
                                <div class="flex gap-2">
                                    <button type="button" @click="rejecting = false" class="flex-1 rounded-xl border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600">Back</button>
                                    <button type="submit" class="flex-1 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Reject</button>
                                </div>
                            </form>
                        </div>
                    @endstaffcan
                @endif

                @if(in_array($l->status, ['approved', 'failed']))
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 space-y-4">
                        <h3 class="text-base font-semibold text-gray-900">{{ $l->status === 'failed' ? 'Retry or reject' : 'Execute' }}</h3>
                        @if($iApproved)
                            <p class="rounded-xl bg-orange-50 border border-orange-200 px-4 py-3 text-sm text-orange-800">You approved this payout, so a different staff member must execute it.</p>
                        @else
                            @staffcan('liquidations.execute')
                                @if($l->isMobileMoney())
                                    <form method="POST" action="{{ route('staff.liquidations.send', $l->id) }}" @submit.prevent="$store.confirm.open('Send UGX {{ number_format((float) $l->net_amount) }} to {{ $d['msisdn'] ?? '' }} now via Yo! Payments?', $el)">
                                        @csrf
                                        <p class="text-sm text-gray-600 mb-3">Sends <strong>{{ $ugx($l->net_amount) }}</strong> to <span class="font-mono">{{ $d['msisdn'] ?? '' }}</span> ({{ $d['account_name'] ?? '' }}) from the Yo! pool.</p>
                                        <x-button type="submit" class="w-full justify-center">{{ $l->status === 'failed' ? 'Retry sending' : 'Send via Yo!' }}</x-button>
                                    </form>
                                @else
                                    <div x-data="{ route: '{{ $account?->yo_bank_identifier ? 'yo' : 'direct' }}' }" class="space-y-4">
                                        <p class="text-sm text-gray-600">Pay <strong>{{ $ugx($l->net_amount) }}</strong> to the bank account shown. Choose how:</p>
                                        <div class="grid grid-cols-1 gap-2">
                                            <label class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition" :class="route === 'yo' ? 'border-orange-500 bg-orange-50/40' : 'border-gray-200'">
                                                <input type="radio" value="yo" x-model="route" class="mt-0.5 text-orange-600 focus:ring-orange-500">
                                                <span>
                                                    <span class="block text-sm font-semibold text-gray-900">Through Yo! Payments <span class="ml-1 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-orange-700">Usual</span></span>
                                                    <span class="block text-xs text-gray-500">From the Yo! pool, to the account registered on Primora's Yo! account.</span>
                                                </span>
                                            </label>
                                            <label class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer transition" :class="route === 'direct' ? 'border-orange-500 bg-orange-50/40' : 'border-gray-200'">
                                                <input type="radio" value="direct" x-model="route" class="mt-0.5 text-orange-600 focus:ring-orange-500">
                                                <span>
                                                    <span class="block text-sm font-semibold text-gray-900">Direct transfer from Primora's bank</span>
                                                    <span class="block text-xs text-gray-500">You make the EFT yourself, then record its reference.</span>
                                                </span>
                                            </label>
                                        </div>

                                        <div x-show="route === 'yo'">
                                            @if($account?->yo_bank_identifier)
                                                <form method="POST" action="{{ route('staff.liquidations.send', $l->id) }}" class="space-y-3" @submit.prevent="$store.confirm.open('Send UGX {{ number_format((float) $l->net_amount) }} to this bank account now via Yo! Payments?', $el)">
                                                    @csrf
                                                    <div>
                                                        <x-label>Transfer type</x-label>
                                                        <x-select name="transfer_type">
                                                            <option value="">Automatic (Yo! decides)</option>
                                                            <option value="EFT">EFT</option>
                                                            <option value="RTGS">RTGS</option>
                                                        </x-select>
                                                    </div>
                                                    <p class="text-xs text-gray-500">Yo! account identifier: <span class="font-mono">{{ $account->yo_bank_identifier }}</span></p>
                                                    <x-button type="submit" class="w-full justify-center">{{ $l->status === 'failed' ? 'Retry via Yo!' : 'Send via Yo!' }}</x-button>
                                                </form>
                                            @else
                                                <div class="rounded-xl bg-orange-50 border border-orange-200 px-4 py-3 text-sm text-orange-800">
                                                    This account isn't registered with Yo! Payments yet.
                                                    @staffcan('liquidations.approve')
                                                        <a href="{{ route('staff.payout-accounts.index', ['status' => 'verified']) }}" class="font-semibold underline">Register it under Payout accounts</a>, then come back.
                                                    @else
                                                        Ask a staff member who verifies payout accounts to register it.
                                                    @endstaffcan
                                                </div>
                                            @endif
                                        </div>

                                        <form method="POST" action="{{ route('staff.liquidations.complete-bank', $l->id) }}" class="space-y-3" x-show="route === 'direct'" x-cloak>
                                            @csrf
                                            <p class="text-sm text-gray-600">Make an EFT of <strong>{{ $ugx($l->net_amount) }}</strong> from Primora's bank account, then record it here.</p>
                                            <div>
                                                <x-label :required="true">Bank reference</x-label>
                                                <x-input name="bank_reference" value="{{ old('bank_reference') }}" required />
                                            </div>
                                            <div>
                                                <x-label :required="true">Transfer date</x-label>
                                                <x-input type="date" name="transfer_date" value="{{ old('transfer_date', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required />
                                            </div>
                                            <x-button type="submit" class="w-full justify-center">Record EFT as done</x-button>
                                        </form>
                                    </div>
                                @endif
                            @else
                                <p class="text-sm text-gray-500">Waiting for a staff member with permission to execute payouts.</p>
                            @endstaffcan
                        @endif
                        @staffcan('liquidations.approve')
                            <form method="POST" action="{{ route('staff.liquidations.reject', $l->id) }}" class="pt-3 border-t border-gray-100 space-y-2" x-data="{ open: false }">
                                @csrf
                                <button type="button" x-show="!open" @click="open = true" class="text-sm font-medium text-red-600 hover:text-red-700">Reject instead…</button>
                                <div x-show="open" x-cloak class="space-y-2">
                                    <x-textarea name="reason" rows="2" placeholder="Reason, shown to the landlord" minlength="5"></x-textarea>
                                    <button type="submit" class="w-full rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Reject</button>
                                </div>
                            </form>
                        @endstaffcan
                    </div>
                @endif

                @if($l->status === 'processing')
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 space-y-3">
                        <h3 class="text-base font-semibold text-gray-900">Waiting for Yo!</h3>
                        @unless($l->isMobileMoney())<p class="text-sm text-gray-600">Bank transfer through Yo!: it completes when Yo! captures it from the pool for transfer to the bank.</p>@endunless
                        <p class="text-sm text-gray-600">Sent {{ $l->sent_at?->diffForHumans() }}. The status is checked automatically every minute. It is never re-sent automatically.</p>
                        <form method="POST" action="{{ route('staff.liquidations.check', $l->id) }}">
                            @csrf
                            <button type="submit" class="w-full rounded-xl border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600">Check status now</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-staff-layout>
