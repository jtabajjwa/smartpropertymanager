<x-staff-layout>
    <x-slot name="header">SMS · {{ $landlord->name }}</x-slot>

    @php
        $statusColors = ['succeeded' => 'green', 'pending' => 'orange', 'indeterminate' => 'orange', 'failed' => 'red'];
        $typeColors = ['purchase' => 'green', 'starter' => 'blue', 'usage' => 'gray', 'refund' => 'blue', 'adjustment' => 'orange'];
        $balance = $landlord->sms_balance;
    @endphp

    <div class="max-w-7xl mx-auto space-y-6" x-data="{ adjustModal: false, saleModal: false }">
        @include('staff.sms._tabs')

        <a href="{{ route('staff.sms.landlords') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900">← All landlord balances</a>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ $landlord->name }}</h3>
                    <p class="text-sm text-gray-500">{{ $landlord->contact_email }} · {{ $landlord->contact_phone }}</p>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ number_format($usedMonth) }} SMS used this month
                        @if($notSentMonth)<span class="text-red-600"> · {{ number_format($notSentMonth) }} not sent for lack of credit</span>@endif
                    </p>
                </div>
                <div class="flex flex-col items-start sm:items-end gap-3">
                    <p class="text-3xl font-bold {{ $balance <= 0 ? 'text-red-600' : ($balance <= $threshold ? 'text-orange-600' : 'text-gray-900') }}">{{ number_format($balance) }} <span class="text-base font-medium text-gray-500">SMS</span></p>
                    @staffcan('sms.credits')
                        <div class="flex gap-2">
                            <x-button type="button" @click="saleModal = true">Record offline sale</x-button>
                            <button type="button" @click="adjustModal = true" class="inline-flex items-center px-4 py-2 rounded-xl border border-gray-200 text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 transition">Adjust balance</button>
                        </div>
                    @endstaffcan
                </div>
            </div>
        </div>

        {{-- SMS payments --}}
        @if($payments->isNotEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">SMS purchases</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Bundle</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Channel</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($payments as $payment)
                                @php $c = $payment->context ?? []; @endphp
                                <tr>
                                    <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">{{ ($payment->completed_at ?? $payment->created_at)->format('d M Y, H:i') }}</td>
                                    <td class="px-6 py-3 text-sm text-gray-900">{{ $c['bundle_name'] ?? 'Custom' }} · {{ number_format($c['quantity'] ?? 0) }} SMS</td>
                                    <td class="px-6 py-3 text-sm text-gray-900 text-right whitespace-nowrap">UGX {{ number_format((float) $payment->amount) }}</td>
                                    <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $payment->gateway === 'manual' ? (\App\Payments\PaymentService::OFFLINE_METHODS[$c['method'] ?? ''] ?? 'Offline') : $payment->displayMsisdn() }}</td>
                                    <td class="px-6 py-3"><x-badge :color="$statusColors[$payment->status] ?? 'gray'">{{ ucfirst($payment->status) }}</x-badge></td>
                                    <td class="px-6 py-3 text-xs font-mono">
                                        @staffcan('payments.view')
                                            <a href="{{ route('staff.payments.show', $payment) }}" class="text-orange-600 hover:text-orange-700">{{ $payment->reference }}</a>
                                        @else
                                            <span class="text-gray-500">{{ $payment->reference }}</span>
                                        @endstaffcan
                                        @if($payment->receipt_number)<span class="block font-sans text-gray-500">Receipt {{ $payment->receipt_number }}</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Ledger --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-base font-semibold text-gray-900">Credit ledger</h3>
                <p class="mt-0.5 text-sm text-gray-500">Every change to this landlord's SMS balance.</p>
            </div>
            @if($ledger->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">No SMS credit activity yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Details</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">By</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">SMS</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($ledger as $entry)
                                <tr>
                                    <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $entry->created_at->format('d M Y, H:i') }}</td>
                                    <td class="px-6 py-3"><x-badge :color="$typeColors[$entry->type] ?? 'gray'">{{ $entry->typeLabel() }}</x-badge></td>
                                    <td class="px-6 py-3 text-sm text-gray-600">{{ $entry->note ?: '—' }}</td>
                                    <td class="px-6 py-3 text-sm text-gray-600">{{ $entry->staffUser?->name ? $entry->staffUser->name.' (staff)' : '—' }}</td>
                                    <td class="px-6 py-3 text-sm text-right whitespace-nowrap font-medium {{ $entry->quantity >= 0 ? 'text-green-700' : 'text-gray-700' }}">{{ $entry->quantity >= 0 ? '+' : '' }}{{ number_format($entry->quantity) }}</td>
                                    <td class="px-6 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ number_format($entry->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($ledger->hasPages())
                    <div class="px-6 py-4 border-t border-gray-200">{{ $ledger->links() }}</div>
                @endif
            @endif
        </div>

        @staffcan('sms.credits')
            {{-- Adjust --}}
            <template x-if="adjustModal">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) adjustModal = false })">
                    <x-modal title="Adjust SMS balance">
                        <form method="POST" action="{{ route('staff.sms.adjust', $landlord) }}" class="space-y-4">
                            @csrf
                            <p class="text-sm text-gray-600">For corrections and goodwill credit only. It is not revenue: use <strong>Record offline sale</strong> when the landlord paid.</p>
                            <div>
                                <x-label :required="true">SMS to add or remove</x-label>
                                <x-input type="number" name="quantity" value="{{ old('quantity') }}" placeholder="e.g. 100 or -100" required />
                                <p class="mt-1 text-xs text-gray-500">Current balance {{ number_format($balance) }}. Negative numbers remove credit (not below zero).</p>
                            </div>
                            <div>
                                <x-label :required="true">Reason</x-label>
                                <x-textarea name="reason" rows="2" required minlength="5">{{ old('reason') }}</x-textarea>
                                <p class="mt-1 text-xs text-gray-500">Shown in the landlord's credit history.</p>
                            </div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Adjust balance</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>

            {{-- Offline sale --}}
            <template x-if="saleModal">
                <div x-data="{
                        show: false,
                        bundles: {{ Js::from($bundles->mapWithKeys(fn ($b) => [$b->id => ['quantity' => $b->quantity, 'price' => (float) $b->price]])) }},
                        bundleId: '{{ old('bundle_id') }}',
                        quantity: '{{ old('quantity') }}',
                        amount: '{{ old('amount') }}',
                        pick() { const b = this.bundles[this.bundleId]; if (b) { this.quantity = b.quantity; this.amount = b.price; } },
                     }"
                     x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) saleModal = false })">
                    <x-modal title="Record offline SMS sale">
                        <form method="POST" action="{{ route('staff.sms.sales.store', $landlord) }}" class="space-y-4">
                            @csrf
                            <p class="text-sm text-gray-600">Money received outside mobile money prompts (cash, bank, direct transfer). The SMS are added to the landlord's balance and the amount counts as SMS sales revenue.</p>
                            <div>
                                <x-label>Bundle</x-label>
                                <x-select name="bundle_id" x-model="bundleId" @change="pick()">
                                    <option value="">Custom quantity</option>
                                    @foreach($bundles as $b)
                                        <option value="{{ $b->id }}">{{ $b->name }}: {{ number_format($b->quantity) }} SMS, UGX {{ number_format((float) $b->price) }}{{ $b->is_active ? '' : ' (hidden)' }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <x-label :required="true">Number of SMS</x-label>
                                    <x-input type="number" name="quantity" min="1" x-model="quantity" required />
                                </div>
                                <div>
                                    <x-label :required="true">Amount received (UGX)</x-label>
                                    <x-input type="number" name="amount" min="1" step="1" x-model="amount" required />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <x-label :required="true">Method</x-label>
                                    <x-select name="method" required>
                                        @foreach(\App\Payments\PaymentService::OFFLINE_METHODS as $value => $label)
                                            <option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                                <div>
                                    <x-label :required="true">Received on</x-label>
                                    <x-input type="date" name="received_on" value="{{ old('received_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <x-label>Receipt / reference</x-label>
                                    <x-input name="reference" value="{{ old('reference') }}" />
                                </div>
                                <div>
                                    <x-label>Payer phone</x-label>
                                    <x-input name="payer_phone" value="{{ old('payer_phone') }}" />
                                </div>
                            </div>
                            <div>
                                <x-label>Note</x-label>
                                <x-textarea name="note" rows="2">{{ old('note') }}</x-textarea>
                            </div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Record sale</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>
        @endstaffcan
    </div>
</x-staff-layout>
