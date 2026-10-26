<x-staff-layout>
    <x-slot name="header">Payment {{ $payment->reference }}</x-slot>

    @php
        $statusColors = ['succeeded' => 'green', 'pending' => 'orange', 'indeterminate' => 'orange', 'failed' => 'red'];
        $c = $payment->context ?? [];
        $network = \App\Payments\Msisdn::network($payment->msisdn);
        $actionLabels = [
            'acdepositfunds' => 'Payment request (acdepositfunds)',
            'actransactioncheckstatus' => 'Status check (actransactioncheckstatus)',
            'ipn' => 'Payment notification (IPN)',
            'failure' => 'Failure notification',
            'request-payment' => 'Payment request',
            'check-status' => 'Status check',
        ];
        $detail = function ($label, $value, $mono = false) {
            return ['label' => $label, 'value' => $value, 'mono' => $mono];
        };
        $details = array_filter([
            $detail('Reference (ExternalReference)', $payment->reference, true),
            $detail('Purpose', ucfirst($payment->purpose)),
            $detail('Amount', $payment->currency.' '.number_format((float) $payment->amount, 2)),
            $detail('Payer', $payment->msisdn ? $payment->displayMsisdn().($network ? " ({$network})" : '') : null),
            $detail('Received by', $payment->gateway === 'manual' ? (\App\Payments\PaymentService::OFFLINE_METHODS[$c['method'] ?? ''] ?? 'Offline') : null),
            $detail('Gateway', strtoupper($payment->gateway)),
            $detail(($network ?? 'Network').' reference (as in the payer\'s SMS)', $payment->gateway === 'manual' ? null : $payment->network_reference, true),
            $detail(($payment->gateway === 'yo' ? 'Yo! transaction reference' : 'Gateway reference'), $payment->gateway_reference, true),
            $detail('Receipt number', $payment->receipt_number, true),
            $detail('Started', $payment->created_at->format('d M Y, H:i:s')),
            $detail('Completed', $payment->completed_at?->format('d M Y, H:i:s')),
            $detail('Last checked with gateway', $payment->last_checked_at?->format('d M Y, H:i:s')),
            $detail('Last notification', $payment->notified_at?->format('d M Y, H:i:s')),
            $detail('Effect applied', $payment->applied_at?->format('d M Y, H:i:s')),
            $detail('Started by', $payment->initiatedByStaffUser ? $payment->initiatedByStaffUser->name.' (Primora staff)' : $payment->initiatedByUser?->name),
        ], fn ($d) => filled($d['value']));
    @endphp

    <div class="max-w-6xl mx-auto">
        <a href="{{ route('staff.payments.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            All payments
        </a>

        {{-- Header --}}
        <div class="mt-3 mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-semibold text-gray-900 font-mono">{{ $payment->reference }}</h2>
                    <x-badge :color="$statusColors[$payment->status] ?? 'gray'">{{ ucfirst($payment->status) }}</x-badge>
                </div>
                <p class="mt-1 text-sm text-gray-600">
                    {{ $payment->currency }} {{ number_format((float) $payment->amount) }}
                    {{ $payment->gateway === 'manual' ? 'received offline ('.(\App\Payments\PaymentService::OFFLINE_METHODS[$c['method'] ?? ''] ?? 'other').')' : 'from '.$payment->displayMsisdn() }}
                    @if($payment->landlord) · <a href="{{ route('staff.landlords.show', $payment->landlord) }}" class="text-orange-600 hover:text-orange-700">{{ $payment->landlord->name }}</a>@endif
                </p>
                @if($payment->status_message)
                    <p class="mt-2 text-sm {{ $payment->status === 'failed' ? 'text-red-700' : 'text-gray-600' }}">{{ $payment->status_message }}</p>
                @endif
            </div>
            @staffcan('payments.check')@if($payment->isOpen())
                <form method="POST" action="{{ route('staff.payments.check', $payment) }}">
                    @csrf
                    <x-button type="submit">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                        Check status now
                    </x-button>
                </form>
            @endif
@endstaffcan
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Details --}}
            <div class="lg:col-span-1 space-y-4">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                    <h3 class="text-sm font-semibold text-gray-900">Details</h3>
                    <dl class="mt-3 divide-y divide-gray-100 text-sm">
                        @foreach($details as $d)
                            <div class="py-2">
                                <dt class="text-xs text-gray-500">{{ $d['label'] }}</dt>
                                <dd class="mt-0.5 text-gray-900 break-all {{ $d['mono'] ? 'font-mono text-xs' : '' }}">{{ $d['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                    <h3 class="text-sm font-semibold text-gray-900">{{ $payment->gateway === 'manual' ? 'Narrative (offline payment)' : 'Narrative sent to '.strtoupper($payment->gateway) }}</h3>
                    <p class="mt-2 text-xs font-mono text-gray-700 break-words bg-gray-50 rounded-lg p-3">{{ $payment->narrative }}</p>
                </div>

                @if($c)
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                        <h3 class="text-sm font-semibold text-gray-900">{{ ucfirst($payment->purpose) }} details</h3>
                        <dl class="mt-3 divide-y divide-gray-100 text-sm">
                            @foreach($c as $key => $value)
                                <div class="py-2 flex justify-between gap-3">
                                    <dt class="text-gray-500">{{ ucfirst(str_replace('_', ' ', $key)) }}</dt>
                                    <dd class="text-gray-900 text-right">{{ is_array($value) ? json_encode($value) : $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </div>

            {{-- Gateway log --}}
            <div class="lg:col-span-2">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                    <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">Gateway log</h3>
                        <span class="text-xs text-gray-500">{{ $payment->gatewayLogs->count() }} {{ Str::plural('exchange', $payment->gatewayLogs->count()) }} · API password masked</span>
                    </div>

                    @if($payment->gatewayLogs->isEmpty())
                        <p class="px-5 py-10 text-center text-sm text-gray-500">No gateway exchanges recorded for this payment.</p>
                    @else
                        <ol class="divide-y divide-gray-100">
                            @foreach($payment->gatewayLogs as $log)
                                @php
                                    $inbound = $log->direction === \App\Models\PaymentGatewayLog::INBOUND;
                                    $ok = $log->isSuccessfulHttp();
                                @endphp
                                <li class="p-5" x-data="{ open: {{ $loop->last ? 'true' : 'false' }} }">
                                    <button type="button" @click="open = !open" class="w-full flex items-start gap-3 text-left">
                                        <span class="mt-0.5 shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-full {{ $inbound ? 'bg-blue-50 text-blue-600' : 'bg-orange-50 text-orange-600' }}" title="{{ $inbound ? 'Gateway called us' : 'We called the gateway' }}">
                                            @if($inbound)
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 4.5l-15 15m0 0h11.25m-11.25 0V8.25"/></svg>
                                            @else
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25"/></svg>
                                            @endif
                                        </span>
                                        <span class="flex-1 min-w-0">
                                            <span class="block text-sm font-medium text-gray-900">{{ $actionLabels[$log->action] ?? $log->action }}</span>
                                            <span class="block text-xs text-gray-500">
                                                {{ $log->created_at->format('d M Y, H:i:s') }} · {{ $inbound ? 'from '.strtoupper($log->gateway) : 'to '.strtoupper($log->gateway) }}
                                                @if($log->duration_ms !== null && !$inbound) · {{ number_format($log->duration_ms) }} ms @endif
                                                @if($log->note) · {{ $log->note }} @endif
                                            </span>
                                        </span>
                                        <span class="shrink-0">
                                            @if($log->error)
                                                <x-badge color="red">No response</x-badge>
                                            @elseif($log->response_status)
                                                <x-badge :color="$ok ? 'green' : 'red'">HTTP {{ $log->response_status }}</x-badge>
                                            @endif
                                        </span>
                                        <svg class="w-4 h-4 mt-1 shrink-0 text-gray-400 transition" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    </button>

                                    <div x-show="open" x-cloak class="mt-4 ml-10 space-y-3">
                                        @if($log->url)
                                            <p class="text-xs text-gray-500 break-all"><span class="font-medium text-gray-700">{{ $inbound ? 'Received at' : 'POST' }}</span> {{ $log->url }}</p>
                                        @endif
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $inbound ? 'Received from gateway' : 'Sent to gateway' }}</p>
                                            <pre class="mt-1 max-h-80 overflow-auto rounded-lg bg-gray-900 text-gray-100 text-xs p-3 whitespace-pre-wrap break-all">{{ $log->prettyRequest() ?: '(empty)' }}</pre>
                                        </div>
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $inbound ? 'Our reply' : 'Gateway response' }}</p>
                                            @if($log->error)
                                                <pre class="mt-1 rounded-lg bg-red-50 text-red-800 text-xs p-3 whitespace-pre-wrap break-all">{{ $log->error }}</pre>
                                            @else
                                                <pre class="mt-1 max-h-80 overflow-auto rounded-lg bg-gray-900 text-gray-100 text-xs p-3 whitespace-pre-wrap break-all">{{ $log->prettyResponse() ?: '(empty)' }}</pre>
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-staff-layout>
