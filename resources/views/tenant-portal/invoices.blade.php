<x-tenant-layout title="Invoices" subtitle="Everything your landlord has billed you">
    @php
        $canPay = $portalTenant->landlord->collect_payments;
        $th = 'px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider';
        $state = function ($invoice) {
            $balance = max(0, (float) $invoice->amount - (float) $invoice->paid);
            $open = in_array($invoice->status, ['unpaid', 'partial'], true) && $balance > 0;
            $late = $open && $invoice->due_date->lt(today());
            [$label, $color] = match (true) {
                $invoice->status === 'paid' => ['Paid', 'green'],
                $invoice->status === 'written_off' => ['Written off', 'gray'],
                $late => ['Overdue', 'red'],
                $invoice->status === 'partial' => ['Part paid', 'orange'],
                default => ['Unpaid', 'orange'],
            };

            return compact('balance', 'open', 'late', 'label', 'color');
        };
    @endphp

    <x-slot name="actions">
        <a href="{{ route('tenant.payments') }}" class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 transition">My payments</a>
        @if($canPay)<a href="{{ route('tenant.pay') }}" class="inline-flex items-center rounded-xl bg-orange-500 hover:bg-orange-600 px-4 py-2 text-sm font-semibold text-white transition">Pay now</a>@endif
    </x-slot>

    <div class="flex items-center justify-between mb-4 lg:hidden">
        <h1 class="text-xl font-bold text-gray-900">Invoices</h1>
        <a href="{{ route('tenant.payments') }}" class="text-sm text-orange-600 hover:text-orange-700">My payments →</a>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 lg:shadow-sm overflow-hidden">
        @if($invoices->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-gray-500">No invoices yet.</p>
        @else
            {{-- phones --}}
            <div class="lg:hidden">
                @foreach($invoices as $invoice)
                    @php $st = $state($invoice); @endphp
                    <div class="px-4 py-3.5 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ $invoice->title }}</p>
                                <p class="text-xs text-gray-500">{{ $invoice->number }} · issued {{ $invoice->invoice_date->format('d M Y') }} · due {{ $invoice->due_date->format('d M Y') }}</p>
                                <x-tenant-unit :lease="$invoice->lease" class="mt-1" />
                            </div>
                            <x-badge :color="$st['color']">{{ $st['label'] }}</x-badge>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-sm">
                            <span class="text-gray-700">
                                UGX {{ number_format((float) $invoice->amount) }}
                                @if($st['open'] && $st['balance'] < (float) $invoice->amount)<span class="text-gray-500">· {{ number_format($st['balance']) }} left</span>@endif
                            </span>
                            <span class="flex gap-4">
                                <a href="{{ route('tenant.invoices.pdf', $invoice->id) }}" target="_blank" class="font-medium text-gray-600 hover:text-gray-900">PDF</a>
                                @if($st['open'] && $canPay)
                                    <a href="{{ route('tenant.pay', ['invoice' => $invoice->id]) }}" class="font-semibold text-orange-600 hover:text-orange-700">Pay</a>
                                @endif
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- desktop --}}
            <table class="hidden lg:table min-w-full">
                <thead class="bg-gray-50/60 border-b border-gray-200">
                    <tr>
                        <th class="{{ $th }}">Invoice</th>
                        <th class="{{ $th }}">Issued</th>
                        <th class="{{ $th }}">Due</th>
                        <th class="{{ $th }}">Status</th>
                        <th class="{{ $th }} text-right">Amount</th>
                        <th class="{{ $th }} text-right">Balance</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($invoices as $invoice)
                        @php $st = $state($invoice); @endphp
                        <tr class="hover:bg-gray-50/60">
                            <td class="px-5 py-3.5">
                                <p class="text-sm font-medium text-gray-900">{{ $invoice->title }}</p>
                                <p class="text-xs text-gray-500">{{ $invoice->number }}</p>
                                <x-tenant-unit :lease="$invoice->lease" class="mt-1" />
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-600 whitespace-nowrap">{{ $invoice->invoice_date->format('d M Y') }}</td>
                            <td class="px-5 py-3.5 text-sm whitespace-nowrap {{ $st['late'] ? 'text-red-600 font-medium' : 'text-gray-600' }}">{{ $invoice->due_date->format('d M Y') }}</td>
                            <td class="px-5 py-3.5"><x-badge :color="$st['color']">{{ $st['label'] }}</x-badge></td>
                            <td class="px-5 py-3.5 text-sm text-gray-700 text-right whitespace-nowrap">{{ number_format((float) $invoice->amount) }}</td>
                            <td class="px-5 py-3.5 text-sm text-right whitespace-nowrap {{ $st['open'] ? 'font-semibold text-gray-900' : 'text-gray-400' }}">{{ $st['open'] ? number_format($st['balance']) : '—' }}</td>
                            <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                <a href="{{ route('tenant.invoices.pdf', $invoice->id) }}" target="_blank" class="text-sm font-medium text-gray-500 hover:text-gray-900">PDF</a>
                                @if($st['open'] && $canPay)
                                    <a href="{{ route('tenant.pay', ['invoice' => $invoice->id]) }}" class="ml-3 inline-flex items-center rounded-lg bg-orange-500 hover:bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white transition">Pay</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if($invoices->hasPages())
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</x-tenant-layout>
