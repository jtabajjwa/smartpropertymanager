<x-tenant-layout title="Payments" subtitle="Money your landlord has received from you">
    @php
        $statusText = ['pending' => ['Awaiting approval', 'orange'], 'indeterminate' => ['Being confirmed', 'orange'], 'failed' => ['Failed', 'red']];
        $th = 'px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider';
        $method = fn ($p) => ucwords(str_replace('_', ' ', $p->payment_method));
        $forWhat = fn ($p) => $p->allocations->isNotEmpty() ? $p->allocations->map(fn ($a) => $a->invoice?->number)->filter()->join(', ') : ($p->status === 'unallocated' ? 'Advance payment' : '—');
    @endphp

    @if($portalTenant->landlord->collect_payments)
        <x-slot name="actions">
            <a href="{{ route('tenant.pay') }}" class="inline-flex items-center rounded-xl bg-orange-500 hover:bg-orange-600 px-4 py-2 text-sm font-semibold text-white transition">Make a payment</a>
        </x-slot>
    @endif

    <h1 class="text-xl font-bold text-gray-900 mb-4 lg:hidden">My payments</h1>

    <div class="space-y-4 lg:space-y-6">
        @if($credit > 0)
            <div class="rounded-2xl border border-green-200 bg-green-50 px-4 lg:px-5 py-3 lg:py-4 text-sm text-green-800">
                You have <strong>UGX {{ number_format($credit) }}</strong> paid in advance. It will be applied to your next invoices.
            </div>
        @endif

        @if($pending->isNotEmpty())
            <section class="bg-white rounded-2xl border border-gray-200 lg:shadow-sm overflow-hidden">
                <h2 class="px-4 lg:px-5 py-3 lg:py-4 border-b border-gray-100 font-semibold text-gray-900">Mobile money attempts</h2>
                @foreach($pending as $tx)
                    <div class="px-4 lg:px-5 py-3 flex items-center justify-between gap-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <div>
                            <p class="text-sm text-gray-900">UGX {{ number_format((float) $tx->amount) }} · {{ $tx->displayMsisdn() }}</p>
                            <p class="text-xs text-gray-500">{{ $tx->created_at->format('d M Y, H:i') }}@if($tx->status === 'failed' && $tx->status_message) · {{ Str::limit($tx->status_message, 80) }}@endif</p>
                        </div>
                        <x-badge :color="$statusText[$tx->status][1] ?? 'gray'">{{ $statusText[$tx->status][0] ?? ucfirst($tx->status) }}</x-badge>
                    </div>
                @endforeach
            </section>
        @endif

        <section class="bg-white rounded-2xl border border-gray-200 lg:shadow-sm overflow-hidden">
            <h2 class="px-4 lg:px-5 py-3 lg:py-4 border-b border-gray-100 font-semibold text-gray-900">Payments received</h2>
            @if($payments->isEmpty())
                <p class="px-4 py-10 text-center text-sm text-gray-500">No payments yet.</p>
            @else
                <div class="lg:hidden">
                    @foreach($payments as $payment)
                        <div class="px-4 py-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm text-gray-900">{{ $payment->payment_date->format('d M Y') }} · {{ $method($payment) }}</p>
                                    <p class="text-xs text-gray-500">{{ $payment->number }}{{ $payment->reference ? ' · '.$payment->reference : '' }}</p>
                                </div>
                                <p class="text-sm font-semibold text-gray-900 whitespace-nowrap">UGX {{ number_format((float) $payment->amount) }}</p>
                            </div>
                            <p class="mt-1 text-xs {{ $forWhat($payment) === 'Advance payment' ? 'text-green-700' : 'text-gray-500' }}">{{ $forWhat($payment) === 'Advance payment' ? 'Advance payment' : 'For '.$forWhat($payment) }}</p>
                        </div>
                    @endforeach
                </div>
                <table class="hidden lg:table min-w-full">
                    <thead class="bg-gray-50/60 border-b border-gray-200">
                        <tr>
                            <th class="{{ $th }}">Date</th>
                            <th class="{{ $th }}">Receipt</th>
                            <th class="{{ $th }}">Method</th>
                            <th class="{{ $th }}">Reference</th>
                            <th class="{{ $th }}">Applied to</th>
                            <th class="{{ $th }} text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($payments as $payment)
                            <tr class="hover:bg-gray-50/60">
                                <td class="px-5 py-3.5 text-sm text-gray-700 whitespace-nowrap">{{ $payment->payment_date->format('d M Y') }}</td>
                                <td class="px-5 py-3.5 text-sm text-gray-700">{{ $payment->number }}</td>
                                <td class="px-5 py-3.5 text-sm text-gray-700">{{ $method($payment) }}</td>
                                <td class="px-5 py-3.5 text-xs font-mono text-gray-500">{{ $payment->reference ?: '—' }}</td>
                                <td class="px-5 py-3.5 text-sm {{ $forWhat($payment) === 'Advance payment' ? 'text-green-700' : 'text-gray-600' }}">{{ $forWhat($payment) }}</td>
                                <td class="px-5 py-3.5 text-sm font-semibold text-gray-900 text-right whitespace-nowrap">UGX {{ number_format((float) $payment->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    @if($payments->hasPages())
        <div class="mt-4">{{ $payments->links() }}</div>
    @endif
</x-tenant-layout>
