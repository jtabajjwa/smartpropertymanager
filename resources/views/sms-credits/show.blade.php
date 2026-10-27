<x-app-layout>
    <x-slot name="header">SMS Credits</x-slot>

    @php
        $statusColors = ['succeeded' => 'green', 'pending' => 'orange', 'indeterminate' => 'orange', 'failed' => 'red'];
        $statusText = ['succeeded' => 'Paid', 'pending' => 'Awaiting approval', 'indeterminate' => 'Being confirmed', 'failed' => 'Failed'];
        $typeColors = ['purchase' => 'green', 'starter' => 'blue', 'usage' => 'gray', 'refund' => 'blue', 'adjustment' => 'orange'];
        $defaultBundle = old('bundle_id', $bundles->count() > 1 ? $bundles->values()[1]->id : $bundles->first()?->id);
    @endphp

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Balance --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Your SMS credit</h3>
                        <p class="mt-1 text-sm text-gray-500">Reminders, invoices and receipts sent to tenants by SMS use credit. Each 160 characters of a message is 1 SMS.</p>
                    </div>
                    <div class="sm:text-right shrink-0">
                        <p class="text-3xl font-bold {{ $balance <= 0 ? 'text-red-600' : ($balance <= $lowThreshold ? 'text-orange-600' : 'text-gray-900') }}">{{ number_format($balance) }} <span class="text-base font-medium text-gray-500">SMS</span></p>
                        <p class="text-xs text-gray-500">{{ number_format($usedThisMonth) }} used this month</p>
                    </div>
                </div>

                @if($balance <= 0)
                    <div class="mt-4 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                        You're out of SMS credit, so tenants are not receiving SMS notifications.
                        @if($notSentThisMonth) {{ number_format($notSentThisMonth) }} {{ Str::plural('message', $notSentThisMonth) }} could not be sent this month. @endif
                        Buy a bundle below to resume.
                    </div>
                @elseif($balance <= $lowThreshold)
                    <div class="mt-4 rounded-xl bg-orange-50 border border-orange-200 px-4 py-3 text-sm text-orange-800">
                        Your SMS credit is running low. When it runs out, tenants will stop receiving SMS notifications.
                    </div>
                @endif
            </div>

            {{-- Payment awaiting approval: live status --}}
            @if($awaiting)
                <div x-data="paymentStatus('{{ route('sms-credits.payment-status', $awaiting->reference) }}', '{{ $awaiting->status }}')"
                     class="rounded-2xl border p-5"
                     :class="{ 'border-orange-200 bg-orange-50': open, 'border-green-200 bg-green-50': status === 'succeeded', 'border-red-200 bg-red-50': status === 'failed' }">
                    <div class="flex items-start gap-4">
                        <div class="shrink-0 mt-0.5">
                            <svg x-show="open" class="w-6 h-6 text-orange-500 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                            <svg x-show="status === 'succeeded'" x-cloak class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <svg x-show="status === 'failed'" x-cloak class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <template x-if="open">
                                <div>
                                    <p class="font-semibold text-gray-900">Approve the payment on {{ $awaiting->displayMsisdn() }}</p>
                                    <p class="mt-1 text-sm text-gray-700">A prompt for <strong>UGX {{ number_format((float) $awaiting->amount) }}</strong> ({{ number_format($awaiting->context['quantity'] ?? 0) }} SMS) has been sent. Enter your mobile money PIN to approve it. This page updates by itself.</p>
                                    <p class="mt-2 text-xs text-gray-500">Reference {{ $awaiting->reference }}<span x-show="status === 'indeterminate'"> · the network is still confirming this payment</span></p>
                                </div>
                            </template>
                            <template x-if="status === 'succeeded'">
                                <div>
                                    <p class="font-semibold text-gray-900">Payment received. Thank you!</p>
                                    <p class="mt-1 text-sm text-gray-700">{{ number_format($awaiting->context['quantity'] ?? 0) }} SMS added<span x-show="smsBalance !== null">. Your balance is now <strong x-text="Number(smsBalance).toLocaleString('en-US') + ' SMS'"></strong></span>.<span x-show="receipt"> Receipt <span x-text="receipt"></span>.</span></p>
                                </div>
                            </template>
                            <template x-if="status === 'failed'">
                                <div>
                                    <p class="font-semibold text-gray-900">The payment didn't go through</p>
                                    <p class="mt-1 text-sm text-gray-700" x-text="message || 'It was declined or timed out. You can try again below.'"></p>
                                </div>
                            </template>
                        </div>
                        <button x-show="!open" x-cloak type="button" @click="window.location.reload()" class="shrink-0 text-sm font-medium text-gray-600 hover:text-gray-900">Refresh</button>
                    </div>
                </div>
            @endif

            {{-- Buy --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">Buy SMS</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Pay with MTN Mobile Money or Airtel Money. Credit is added as soon as the payment is approved and never expires.</p>
                </div>

                @if(! $available)
                    <div class="px-6 py-8 text-center text-sm text-gray-600">Mobile money payments aren't available yet. Please contact Primora to buy SMS.</div>
                @elseif($bundles->isEmpty())
                    <div class="px-6 py-8 text-center text-sm text-gray-600">No SMS bundles are on sale right now. Please contact Primora.</div>
                @else
                    <form method="POST" action="{{ route('sms-credits.buy') }}" class="p-6 space-y-6"
                          x-data="{
                              bundles: {{ Js::from($bundles->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'quantity' => $b->quantity, 'price' => (float) $b->price])) }},
                              bundleId: {{ (int) $defaultBundle }},
                              submitting: false,
                              get bundle() { return this.bundles.find(b => b.id === Number(this.bundleId)) },
                              fmt(n) { return Number(n).toLocaleString('en-US') },
                          }"
                          @submit="submitting = true">
                        @csrf

                        <div>
                            <x-label>Bundle</x-label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                @foreach($bundles as $bundle)
                                    <label class="relative flex cursor-pointer rounded-xl border p-4 transition" :class="Number(bundleId) === {{ $bundle->id }} ? 'border-orange-500 ring-1 ring-orange-500 bg-orange-50/40' : 'border-gray-200 hover:border-gray-300'">
                                        <input type="radio" name="bundle_id" value="{{ $bundle->id }}" x-model="bundleId" class="sr-only">
                                        <div class="flex-1">
                                            <p class="text-sm font-semibold text-gray-900">{{ $bundle->name }}</p>
                                            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($bundle->quantity) }} <span class="text-sm font-medium text-gray-500">SMS</span></p>
                                            <p class="mt-1 text-sm text-gray-700">UGX {{ number_format((float) $bundle->price) }}</p>
                                            <p class="text-xs text-gray-500">UGX {{ rtrim(rtrim(number_format($bundle->unitPrice(), 1), '0'), '.') }} per SMS</p>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 items-end">
                            <div>
                                <x-label :required="true">Mobile money number</x-label>
                                <x-input name="phone" type="tel" inputmode="tel" value="{{ old('phone', $defaultPhone) }}" placeholder="0772 123 456" required />
                                <p class="mt-1 text-xs text-gray-500">MTN or Airtel. You'll get a prompt on this phone to approve with your PIN.</p>
                            </div>
                            <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                                <p class="text-xs uppercase tracking-wide text-gray-500">You'll pay</p>
                                <p class="mt-1 text-2xl font-bold text-gray-900" x-text="bundle ? 'UGX ' + fmt(bundle.price) : '—'"></p>
                                <p class="text-xs text-gray-500" x-text="bundle ? bundle.name + ' · ' + fmt(bundle.quantity) + ' SMS' : ''"></p>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <x-button type="submit" x-bind:disabled="!bundle || submitting">
                                <span x-show="!submitting">Send payment request</span>
                                <span x-show="submitting" x-cloak>Sending…</span>
                            </x-button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- Purchases --}}
            @if($recentPayments->isNotEmpty())
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Recent purchases</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Bundle</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Paid by</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($recentPayments as $payment)
                                    @php $c = $payment->context ?? []; @endphp
                                    <tr>
                                        <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $payment->created_at->format('d M Y, H:i') }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-900">{{ $c['bundle_name'] ?? 'SMS' }} · {{ number_format($c['quantity'] ?? 0) }} SMS</td>
                                        <td class="px-6 py-3 text-sm text-gray-900 text-right whitespace-nowrap">UGX {{ number_format((float) $payment->amount) }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $payment->gateway === 'manual' ? (\App\Payments\PaymentService::OFFLINE_METHODS[$c['method'] ?? ''] ?? 'Offline') : $payment->displayMsisdn() }}</td>
                                        <td class="px-6 py-3"><x-badge :color="$statusColors[$payment->status] ?? 'gray'">{{ $statusText[$payment->status] ?? ucfirst($payment->status) }}</x-badge></td>
                                        <td class="px-6 py-3 text-xs text-gray-500 font-mono">
                                            {{ $payment->reference }}
                                            @if($payment->receipt_number)<span class="block font-sans">Receipt {{ $payment->receipt_number }}</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Credit history --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">Credit history</h3>
                </div>
                @if($history->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-gray-500">No SMS credit activity yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Details</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">SMS</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($history as $entry)
                                    <tr>
                                        <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $entry->created_at->format('d M Y, H:i') }}</td>
                                        <td class="px-6 py-3"><x-badge :color="$typeColors[$entry->type] ?? 'gray'">{{ $entry->typeLabel() }}</x-badge></td>
                                        <td class="px-6 py-3 text-sm text-gray-600">{{ $entry->note ?: '—' }}</td>
                                        <td class="px-6 py-3 text-sm text-right whitespace-nowrap font-medium {{ $entry->quantity >= 0 ? 'text-green-700' : 'text-gray-700' }}">{{ $entry->quantity >= 0 ? '+' : '' }}{{ number_format($entry->quantity) }}</td>
                                        <td class="px-6 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ number_format($entry->balance_after) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($history->hasPages())
                        <div class="px-6 py-4 border-t border-gray-200">{{ $history->links() }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>

</x-app-layout>
