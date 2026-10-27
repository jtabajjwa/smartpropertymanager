<x-app-layout>
    <x-slot name="header">Payouts</x-slot>

    @php
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $verified = $accounts->where('status', 'verified')->values();
    @endphp

    <div class="max-w-6xl mx-auto space-y-6" x-data="{ accountModal: {{ $errors->hasAny(['type', 'account_name', 'msisdn', 'bank_name', 'account_number']) ? 'true' : 'false' }} }">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Payouts</h1>
            <p class="mt-1 text-sm text-gray-500">Rent your tenants paid by mobile money is collected by {{ config('app.name') }} and held for you. Request a payout to your mobile money or bank account whenever you like.</p>
        </div>

        {{-- Balances --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-2xl bg-navy-800 text-white p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-300">Available to pay out</p>
                <p class="mt-2 text-3xl font-bold">{{ $ugx($available) }}</p>
                <p class="mt-1 text-xs text-gray-400">Collected for you and not yet paid out or requested.</p>
            </div>
            <div class="rounded-2xl bg-white border border-gray-200 p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Requests in progress</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $ugx($reserved) }}</p>
                <p class="mt-1 text-xs text-gray-500">Awaiting approval or being paid.</p>
            </div>
            <div class="rounded-2xl bg-white border border-gray-200 p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Paid out to date</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $ugx($paidOut) }}</p>
                <p class="mt-1 text-xs text-gray-500">Received in your accounts, after transfer charges.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">
            {{-- Request --}}
            <section class="lg:col-span-3 bg-white rounded-2xl border border-gray-200 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900">Request a payout</h2>
                    <p class="mt-0.5 text-sm text-gray-500">Primora reviews each request before paying it.</p>
                </div>

                @if(! $canRequest)
                    <p class="px-6 py-8 text-sm text-gray-600">You're in staff mode. Only the landlord's own administrators can request payouts.</p>
                @elseif($verified->isEmpty())
                    <div class="px-6 py-8 text-sm text-gray-600">
                        <p class="font-medium text-gray-900">Add a payout account first.</p>
                        <p class="mt-1">Add your mobile money number or bank account. Once Primora has verified it, you can request payouts to it.</p>
                        <x-button type="button" class="mt-4" @click="accountModal = true">Add payout account</x-button>
                    </div>
                @elseif($available < $min)
                    <p class="px-6 py-8 text-sm text-gray-600">Your available balance is below the minimum payout of {{ $ugx($min) }}.</p>
                @else
                    <form method="POST" action="{{ route('payouts.store') }}" class="p-6 space-y-5"
                          x-data="{
                              accounts: {{ Js::from($verified->mapWithKeys(fn ($a) => [$a->id => $a->type])) }},
                              bands: {{ Js::from($feeBands) }},
                              accountId: '{{ old('payout_account_id', $verified->first()->id) }}',
                              amount: '{{ old('amount', (int) $available) }}',
                              available: {{ (float) $available }},
                              busy: false,
                              get method() { return this.accounts[this.accountId] || 'mobile_money' },
                              get fee() {
                                  const a = Number(this.amount) || 0;
                                  const t = (this.bands[this.method] || []).find(t => a >= t.min && (t.max === null || a <= t.max));
                                  return t ? Math.ceil(t.fixed + a * t.percent / 100) : 0;
                              },
                              get net() { return Math.max(0, (Number(this.amount) || 0) - this.fee) },
                              fmt(n) { return Number(n).toLocaleString('en-US') },
                          }" @submit="busy = true">
                        @csrf
                        <div>
                            <x-label :required="true">Pay to</x-label>
                            <x-select name="payout_account_id" x-model="accountId">
                                @foreach($verified as $account)
                                    <option value="{{ $account->id }}">{{ $account->summary() }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Amount (UGX)</x-label>
                                <x-input type="number" name="amount" min="{{ $min }}" max="{{ (int) $available }}" step="1" x-model="amount" required />
                                <button type="button" class="mt-1 text-xs font-medium text-orange-600 hover:text-orange-700" @click="amount = Math.floor(available)">Request everything available</button>
                                @error('amount')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="rounded-xl bg-gray-50 border border-gray-100 p-4 text-sm">
                                <div class="flex justify-between text-gray-600"><span>Amount</span><span x-text="'UGX ' + fmt(amount || 0)"></span></div>
                                <div class="mt-1 flex justify-between text-gray-600"><span>Transfer charge</span><span x-text="'− UGX ' + fmt(fee)"></span></div>
                                <div class="mt-2 pt-2 border-t border-gray-200 flex justify-between font-semibold text-gray-900"><span>You'll receive</span><span x-text="'UGX ' + fmt(net)"></span></div>
                            </div>
                        </div>
                        <p class="text-xs text-red-600" x-show="Number(amount) > available" x-cloak>That's more than your available balance.</p>
                        <p class="text-xs text-red-600" x-show="method === 'mobile_money' && net > {{ $mobileMax }}" x-cloak>Mobile money payouts are limited to {{ $ugx($mobileMax) }} each.</p>
                        <div>
                            <x-label>Note for Primora (optional)</x-label>
                            <x-input name="note" value="{{ old('note') }}" maxlength="300" />
                        </div>
                        <div class="sm:w-1/2">
                            <x-label :required="true">Your password</x-label>
                            <x-input type="password" name="password" autocomplete="current-password" required />
                            <p class="mt-1 text-xs text-gray-500">To confirm it's you.</p>
                            @error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex justify-end">
                            <x-button type="submit" x-bind:disabled="busy || Number(amount) < {{ $min }} || Number(amount) > available || net <= 0">
                                <span x-show="!busy">Request payout</span>
                                <span x-show="busy" x-cloak>Sending…</span>
                            </x-button>
                        </div>
                    </form>
                @endif
            </section>

            {{-- Accounts --}}
            <section class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">Payout accounts</h2>
                    @if($canRequest)
                        <button type="button" class="text-sm font-medium text-orange-600 hover:text-orange-700" @click="accountModal = true">+ Add</button>
                    @endif
                </div>
                @forelse($accounts as $account)
                    <div class="px-6 py-3.5 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ \App\Models\PayoutAccount::TYPES[$account->type] }}</p>
                                <p class="text-sm text-gray-600 break-words">{{ $account->summary() }}</p>
                                @if($account->status === 'rejected' && $account->review_note)<p class="mt-1 text-xs text-red-600">{{ $account->review_note }}</p>@endif
                            </div>
                            <div class="flex flex-col items-end gap-1.5 shrink-0">
                                <x-badge :color="$account->statusColor()">{{ $account->statusLabel() }}</x-badge>
                                @if($canRequest)
                                    <form method="POST" action="{{ route('payouts.accounts.destroy', $account) }}" @submit.prevent="$store.confirm.open('Remove this payout account?', $el)">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-gray-400 hover:text-red-600">Remove</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="px-6 py-8 text-center text-sm text-gray-500">No payout accounts yet.</p>
                @endforelse
            </section>
        </div>

        {{-- History --}}
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100"><h2 class="text-base font-semibold text-gray-900">Payout history</h2></div>
            @if($payouts->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">No payouts yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50/60 border-b border-gray-200">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Requested</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">To</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Charge</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">You receive</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($payouts as $p)
                                <tr>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <span class="text-gray-900">{{ $p->created_at->format('d M Y') }}</span>
                                        <span class="block text-xs font-mono text-gray-400">{{ $p->reference }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-gray-700">{{ $p->destinationSummary() }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap">{{ number_format((float) $p->amount) }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap text-gray-500">{{ number_format((float) $p->fee) }}</td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap font-semibold text-gray-900">{{ number_format((float) $p->net_amount) }}</td>
                                    <td class="px-5 py-3">
                                        <x-badge :color="$p->status === 'failed' ? 'blue' : $p->statusColor()">{{ $p->landlordStatus() }}</x-badge>
                                        @if($p->status === 'completed')
                                            <span class="block mt-1 text-xs text-gray-500">{{ $p->completed_at?->format('d M Y') }}{{ $p->network_reference ? ' · '.$p->network_reference : '' }}{{ $p->bank_reference ? ' · '.$p->bank_reference : '' }}</span>
                                        @elseif($p->status === 'rejected' && $p->review_note)
                                            <span class="block mt-1 text-xs text-red-600">{{ $p->review_note }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        @if($p->status === 'pending' && $canRequest)
                                            <form method="POST" action="{{ route('payouts.cancel', $p) }}" @submit.prevent="$store.confirm.open('Cancel this payout request?', $el)">
                                                @csrf
                                                <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700">Cancel</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($payouts->hasPages())
                    <div class="px-6 py-4 border-t border-gray-100">{{ $payouts->links() }}</div>
                @endif
            @endif
        </section>

        {{-- Add account --}}
        @if($canRequest)
            <template x-if="accountModal">
                <div x-data="{ show: false, type: '{{ old('type', 'mobile_money') }}' }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) accountModal = false })">
                    <x-modal title="Add payout account">
                        <form method="POST" action="{{ route('payouts.accounts.store') }}" class="space-y-4">
                            @csrf
                            <div class="grid grid-cols-2 gap-2">
                                @foreach(\App\Models\PayoutAccount::TYPES as $value => $label)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="type" value="{{ $value }}" x-model="type" class="sr-only">
                                        <span class="block rounded-xl border px-3 py-2.5 text-center text-sm font-medium transition" :class="type === '{{ $value }}' ? 'border-orange-500 bg-orange-50 text-orange-700' : 'border-gray-200 text-gray-600'">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div>
                                <x-label :required="true"><span x-text="type === 'bank' ? 'Account name' : 'Registered name on the number'"></span></x-label>
                                <x-input name="account_name" value="{{ old('account_name') }}" maxlength="150" required />
                                @error('account_name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <template x-if="type === 'mobile_money'">
                                <div>
                                    <x-label :required="true">MTN or Airtel number</x-label>
                                    <x-input name="msisdn" type="tel" value="{{ old('msisdn') }}" placeholder="0772 123 456" />
                                    @error('msisdn')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                </div>
                            </template>
                            <template x-if="type === 'bank'">
                                <div class="space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <x-label :required="true">Bank</x-label>
                                            <x-input name="bank_name" value="{{ old('bank_name') }}" placeholder="e.g. Stanbic Bank Uganda" />
                                            @error('bank_name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                        </div>
                                        <div>
                                            <x-label>Branch</x-label>
                                            <x-input name="bank_branch" value="{{ old('bank_branch') }}" />
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <x-label :required="true">Account number</x-label>
                                            <x-input name="account_number" value="{{ old('account_number') }}" />
                                            @error('account_number')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                        </div>
                                        <div>
                                            <x-label>SWIFT code</x-label>
                                            <x-input name="swift_code" value="{{ old('swift_code') }}" />
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <div>
                                <x-label :required="true">Your password</x-label>
                                <x-input type="password" name="password" autocomplete="current-password" required />
                                @error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <p class="text-xs text-gray-500">Primora verifies new accounts before any money can be sent to them. Your administrators are emailed whenever an account is added.</p>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Add account</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>
        @endif
    </div>
</x-app-layout>
