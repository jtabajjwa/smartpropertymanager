<x-tenant-layout title="Home" :subtitle="'Welcome back, '.(Str::before($tenant->name, ' ') ?: $tenant->name)">
    @php
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $canPay = $landlord->collect_payments;
        $th = 'px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider';
    @endphp

    <x-slot name="actions">
            <a href="{{ route('tenant.issues.create') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 transition">Report an issue</a>
            @if($canPay)
                <a href="{{ route('tenant.pay') }}" class="inline-flex items-center rounded-xl bg-orange-500 hover:bg-orange-600 px-4 py-2 text-sm font-semibold text-white transition">{{ $outstanding > 0 ? 'Pay now' : 'Pay in advance' }}</a>
            @endif
    </x-slot>

    <div class="space-y-5 lg:space-y-6">
        <div class="lg:hidden">
            <p class="text-sm text-gray-500">Hello,</p>
            <h1 class="text-2xl font-bold text-gray-900">{{ Str::before($tenant->name, ' ') ?: $tenant->name }}</h1>
        </div>

        {{-- Payment awaiting approval --}}
        @if($awaiting)
            <div x-data="paymentStatus('{{ route('tenant.pay.status', $awaiting->reference) }}', '{{ $awaiting->status }}')"
                 class="rounded-2xl border p-4 lg:p-5"
                 :class="{ 'border-orange-200 bg-orange-50': open, 'border-green-200 bg-green-50': status === 'succeeded', 'border-red-200 bg-red-50': status === 'failed' }">
                <template x-if="open">
                    <div class="flex gap-3">
                        <svg class="w-5 h-5 mt-0.5 shrink-0 text-orange-500 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        <div>
                            <p class="font-semibold text-gray-900">Approve UGX {{ number_format((float) $awaiting->amount) }} on {{ $awaiting->displayMsisdn() }}</p>
                            <p class="mt-0.5 text-sm text-gray-700">Enter your mobile money PIN on your phone. This page updates by itself.</p>
                        </div>
                    </div>
                </template>
                <template x-if="status === 'succeeded'">
                    <div>
                        <p class="font-semibold text-gray-900">Payment received. Thank you!</p>
                        <p class="mt-0.5 text-sm text-gray-700"><span x-show="receipt">Reference <span x-text="receipt"></span>. </span><a href="{{ route('tenant.dashboard') }}" class="font-medium text-green-800 underline">Refresh your balance</a></p>
                    </div>
                </template>
                <template x-if="status === 'failed'">
                    <div>
                        <p class="font-semibold text-gray-900">The payment didn't go through</p>
                        <p class="mt-0.5 text-sm text-gray-700"><span x-text="message || 'It was declined or timed out.'"></span> <a href="{{ route('tenant.pay') }}" class="font-medium underline">Try again</a></p>
                    </div>
                </template>
            </div>
        @endif

        {{-- Balance hero (phones) --}}
        <div class="lg:hidden rounded-2xl bg-navy-800 text-white p-5 shadow-sm">
            <p class="text-xs uppercase tracking-wide text-gray-300">Outstanding balance</p>
            <p class="mt-1 text-3xl font-bold">{{ $ugx($outstanding) }}</p>
            <div class="mt-2 text-sm text-gray-300 space-y-0.5">
                @if($overdue > 0)<p class="text-orange-300 font-medium">{{ $ugx($overdue) }} is overdue</p>@endif
                @if($nextDue)
                    <p>Next due: {{ $ugx($nextDue->balance) }} on {{ $nextDue->due_date->format('d M Y') }}</p>
                @elseif($outstanding <= 0)
                    <p>You're all paid up. Thank you!</p>
                @endif
                @if($credit > 0)<p>You have {{ $ugx($credit) }} paid in advance, to be applied to your next invoices.</p>@endif
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                @if($canPay)
                    <a href="{{ route('tenant.pay') }}" class="inline-flex items-center rounded-xl bg-orange-500 hover:bg-orange-600 px-4 py-2.5 text-sm font-semibold text-white transition">{{ $outstanding > 0 ? 'Pay now' : 'Pay in advance' }}</a>
                @endif
                <a href="{{ route('tenant.issues.create') }}" class="inline-flex items-center rounded-xl border border-white/25 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-white transition">Report an issue</a>
            </div>
        </div>

        {{-- Quick figures (phones) --}}
        <div class="lg:hidden grid grid-cols-3 gap-3">
            <a href="{{ route('tenant.invoices') }}" class="bg-white rounded-2xl border border-gray-200 p-3.5">
                <p class="text-[11px] uppercase tracking-wide text-gray-500">Unpaid invoices</p>
                <p class="mt-1 text-xl font-bold text-gray-900">{{ $openInvoices->count() }}</p>
            </a>
            <a href="{{ route('tenant.issues.index') }}" class="bg-white rounded-2xl border border-gray-200 p-3.5">
                <p class="text-[11px] uppercase tracking-wide text-gray-500">Open issues</p>
                <p class="mt-1 text-xl font-bold {{ $openIssues ? 'text-orange-600' : 'text-gray-900' }}">{{ $openIssues }}</p>
            </a>
            <div class="bg-white rounded-2xl border border-gray-200 p-3.5">
                <p class="text-[11px] uppercase tracking-wide text-gray-500">Deposit held</p>
                <p class="mt-1 text-base font-bold text-gray-900 leading-7">{{ number_format($deposit) }}</p>
            </div>
        </div>

        {{-- KPI strip (desktop) --}}
        <div class="hidden lg:grid grid-cols-4 gap-5">
            <div class="rounded-2xl bg-navy-800 text-white p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-300">Outstanding balance</p>
                <p class="mt-2 text-2xl font-bold">{{ $ugx($outstanding) }}</p>
                <p class="mt-1 text-xs {{ $overdue > 0 ? 'text-orange-300 font-medium' : 'text-gray-400' }}">{{ $overdue > 0 ? $ugx($overdue).' overdue' : ($outstanding > 0 ? 'Nothing overdue' : 'All paid up. Thank you!') }}</p>
            </div>
            <div class="rounded-2xl bg-white border border-gray-200 p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Next payment</p>
                @php $nextUp = $nextDue ?? $openInvoices->first(); @endphp
                @if($nextUp)
                    <p class="mt-2 text-2xl font-bold text-gray-900">{{ $ugx($nextUp->balance) }}</p>
                    @if($nextUp->due_date->lt(today()))
                        <p class="mt-1 text-xs font-medium text-red-600">Overdue since {{ $nextUp->due_date->format('d M Y') }}</p>
                    @else
                        <p class="mt-1 text-xs text-gray-500">Due {{ $nextUp->due_date->format('d M Y') }} · {{ $nextUp->due_date->isToday() ? 'today' : 'in '.(int) today()->diffInDays($nextUp->due_date).' days' }}</p>
                    @endif
                @else
                    <p class="mt-2 text-2xl font-bold text-gray-400">—</p>
                    <p class="mt-1 text-xs text-gray-500">No upcoming invoices</p>
                @endif
            </div>
            <div class="rounded-2xl bg-white border border-gray-200 p-5 shadow-sm">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Paid in advance</p>
                <p class="mt-2 text-2xl font-bold {{ $credit > 0 ? 'text-green-700' : 'text-gray-900' }}">{{ $ugx($credit) }}</p>
                <p class="mt-1 text-xs text-gray-500">Deposit held: {{ $ugx($deposit) }}</p>
            </div>
            <a href="{{ route('tenant.issues.index') }}" class="rounded-2xl bg-white border border-gray-200 p-5 shadow-sm hover:border-orange-300 transition">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Open issues</p>
                <p class="mt-2 text-2xl font-bold {{ $openIssues ? 'text-orange-600' : 'text-gray-900' }}">{{ $openIssues }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $openIssues ? 'Being handled by your landlord' : 'Nothing outstanding' }}</p>
            </a>
        </div>

        <div class="grid gap-5 lg:grid-cols-3 lg:gap-6 lg:items-start">
            {{-- Unpaid invoices --}}
            <section class="bg-white rounded-2xl border border-gray-200 lg:col-span-2 lg:row-start-1 lg:shadow-sm">
                <div class="px-4 lg:px-5 py-3 lg:py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900">Unpaid invoices</h2>
                    <a href="{{ route('tenant.invoices') }}" class="text-sm text-orange-600 hover:text-orange-700">All invoices</a>
                </div>
                @if($openInvoices->isEmpty())
                    <p class="px-4 py-8 text-center text-sm text-gray-500">No unpaid invoices. You're all paid up.</p>
                @else
                    {{-- phones --}}
                    <div class="lg:hidden">
                        @foreach($openInvoices as $invoice)
                            @php $late = $invoice->due_date->lt(today()); @endphp
                            <div class="px-4 py-3 flex items-center gap-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $invoice->title }}</p>
                                    <p class="text-xs {{ $late ? 'text-red-600' : 'text-gray-500' }}">{{ $invoice->number }} · {{ $late ? 'overdue since' : 'due' }} {{ $invoice->due_date->format('d M Y') }}</p>
                                    <x-tenant-unit :lease="$invoice->lease" class="mt-1" />
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-gray-900 whitespace-nowrap">{{ number_format($invoice->balance) }}</p>
                                    @if($canPay)
                                        <a href="{{ route('tenant.pay', ['invoice' => $invoice->id]) }}" class="text-xs font-semibold text-orange-600 hover:text-orange-700">Pay</a>
                                    @else
                                        <a href="{{ route('tenant.invoices.pdf', $invoice->id) }}" target="_blank" class="text-xs font-medium text-gray-500 hover:text-gray-900">View</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    {{-- desktop --}}
                    <table class="hidden lg:table min-w-full">
                        <thead class="bg-gray-50/60">
                            <tr>
                                <th class="{{ $th }}">Invoice</th>
                                <th class="{{ $th }}">Due</th>
                                <th class="{{ $th }} text-right">Amount</th>
                                <th class="{{ $th }} text-right">Balance</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($openInvoices as $invoice)
                                @php $late = $invoice->due_date->lt(today()); @endphp
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-3.5">
                                        <p class="text-sm font-medium text-gray-900">{{ $invoice->title }}</p>
                                        <p class="text-xs text-gray-500">{{ $invoice->number }}</p>
                                        <x-tenant-unit :lease="$invoice->lease" class="mt-1" />
                                    </td>
                                    <td class="px-5 py-3.5 text-sm whitespace-nowrap">
                                        <span class="{{ $late ? 'text-red-600 font-medium' : 'text-gray-700' }}">{{ $invoice->due_date->format('d M Y') }}</span>
                                        @if($late)<span class="block text-xs text-red-500">Overdue</span>@endif
                                    </td>
                                    <td class="px-5 py-3.5 text-sm text-gray-600 text-right whitespace-nowrap">{{ number_format((float) $invoice->amount) }}</td>
                                    <td class="px-5 py-3.5 text-sm font-semibold text-gray-900 text-right whitespace-nowrap">{{ number_format($invoice->balance) }}</td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                        <a href="{{ route('tenant.invoices.pdf', $invoice->id) }}" target="_blank" class="text-sm font-medium text-gray-500 hover:text-gray-900">PDF</a>
                                        @if($canPay)
                                            <a href="{{ route('tenant.pay', ['invoice' => $invoice->id]) }}" class="ml-3 inline-flex items-center rounded-lg bg-orange-500 hover:bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white transition">Pay</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200">
                                <td colspan="3" class="px-5 py-3 text-sm font-medium text-gray-600 text-right">Total outstanding</td>
                                <td class="px-5 py-3 text-sm font-bold text-gray-900 text-right whitespace-nowrap">{{ number_format($outstanding) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                @endif
            </section>

            {{-- Issues --}}
            <section class="bg-white rounded-2xl border border-gray-200 lg:col-start-3 lg:row-start-1 lg:shadow-sm">
                <div class="px-4 lg:px-5 py-3 lg:py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900">Issues you reported</h2>
                    <a href="{{ route('tenant.issues.create') }}" class="text-sm text-orange-600 hover:text-orange-700">+ Report</a>
                </div>
                @forelse($issues as $issue)
                    <a href="{{ route('tenant.issues.show', $issue->id) }}" class="px-4 lg:px-5 py-3 flex items-center gap-3 hover:bg-gray-50 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $issue->title }}</p>
                            <p class="text-xs text-gray-500">{{ $issue->number }} · {{ $issue->updated_at->diffForHumans() }}</p>
                        </div>
                        <x-badge :color="$issue->statusColor()">{{ $issue->statusLabel() }}</x-badge>
                    </a>
                @empty
                    <p class="px-4 lg:px-5 py-6 text-center text-sm text-gray-500">Something broken or need help? <a href="{{ route('tenant.issues.create') }}" class="text-orange-600 font-medium">Report it here</a>.</p>
                @endforelse
            </section>

            {{-- Tenancy --}}
            <section class="bg-white rounded-2xl border border-gray-200 lg:col-start-3 lg:row-start-2 lg:shadow-sm">
                <div class="px-4 lg:px-5 py-3 lg:py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-900">My tenancy</h2></div>
                @forelse($leases as $lease)
                    <div class="px-4 lg:px-5 py-3 lg:py-4 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">Unit {{ $lease->unit?->name }}{{ $lease->unit?->estate ? ', '.$lease->unit->estate->name : '' }}</p>
                                <p class="text-xs text-gray-500">{{ $lease->number }} · {{ $lease->start_date->format('d M Y') }} – {{ $lease->end_date?->format('d M Y') ?? 'open-ended' }}</p>
                            </div>
                            <x-badge :color="$lease->status === 'active' ? 'green' : 'gray'">{{ ucfirst($lease->status) }}</x-badge>
                        </div>
                        <dl class="hidden lg:grid mt-3 grid-cols-2 gap-3 text-xs">
                            <div class="rounded-lg bg-gray-50 px-3 py-2"><dt class="text-gray-500">Rent</dt><dd class="mt-0.5 text-sm font-semibold text-gray-900">{{ $ugx($lease->rent_amount) }}</dd></div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2"><dt class="text-gray-500">Billed</dt><dd class="mt-0.5 text-sm font-semibold text-gray-900">{{ ucfirst(str_replace('_', ' ', $lease->billing_cycle)) }}</dd></div>
                        </dl>
                        <div class="mt-2 lg:mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span class="text-gray-700 lg:hidden">Rent {{ $ugx($lease->rent_amount) }} / {{ str_replace('_', ' ', $lease->billing_cycle) }}</span>
                            <a href="{{ route('tenant.leases.agreement', $lease->id) }}" target="_blank" class="font-medium text-orange-600 hover:text-orange-700">Tenancy agreement ↗</a>
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-gray-500">No tenancy found.</p>
                @endforelse
            </section>

            {{-- Recent payments --}}
            <section class="bg-white rounded-2xl border border-gray-200 lg:col-span-2 lg:col-start-1 lg:row-start-2 lg:shadow-sm">
                <div class="px-4 lg:px-5 py-3 lg:py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900">Recent payments</h2>
                    <a href="{{ route('tenant.payments') }}" class="text-sm text-orange-600 hover:text-orange-700">All payments</a>
                </div>
                @if($payments->isEmpty())
                    <p class="px-4 py-6 text-center text-sm text-gray-500">No payments yet.</p>
                @else
                    <div class="lg:hidden">
                        @foreach($payments as $payment)
                            <div class="px-4 py-3 flex items-center justify-between gap-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                                <div>
                                    <p class="text-sm text-gray-900">{{ $payment->payment_date->format('d M Y') }}</p>
                                    <p class="text-xs text-gray-500">{{ ucwords(str_replace('_', ' ', $payment->payment_method)) }}{{ $payment->reference ? ' · '.$payment->reference : '' }}</p>
                                </div>
                                <p class="text-sm font-semibold text-gray-900 whitespace-nowrap">{{ $ugx($payment->amount) }}</p>
                            </div>
                        @endforeach
                    </div>
                    <table class="hidden lg:table min-w-full">
                        <thead class="bg-gray-50/60">
                            <tr>
                                <th class="{{ $th }}">Date</th>
                                <th class="{{ $th }}">Method</th>
                                <th class="{{ $th }}">Reference</th>
                                <th class="{{ $th }} text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($payments as $payment)
                                <tr>
                                    <td class="px-5 py-3 text-sm text-gray-700 whitespace-nowrap">{{ $payment->payment_date->format('d M Y') }}</td>
                                    <td class="px-5 py-3 text-sm text-gray-700">{{ ucwords(str_replace('_', ' ', $payment->payment_method)) }}</td>
                                    <td class="px-5 py-3 text-xs text-gray-500 font-mono">{{ $payment->reference ?: '—' }}</td>
                                    <td class="px-5 py-3 text-sm font-semibold text-gray-900 text-right whitespace-nowrap">{{ $ugx($payment->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
    </div>

</x-tenant-layout>
