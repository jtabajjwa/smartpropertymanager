<x-app-layout>
    <x-slot name="header">Taxes &amp; levies</x-slot>

    @php
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $n = fn ($v) => abs((float) $v) < 0.005 ? '–' : (($v < 0 ? '(' : '').number_format(abs((float) $v)).($v < 0 ? ')' : ''));
        $label = $books->fiscalYearLabel($year);
        [$fyFrom, $fyTo] = $books->fiscalYearRange($year);
        $lines = collect($result['lines']);
        $openPayment = $errors->hasAny(['landlord_tax_id', 'amount', 'paid_on', 'reference', 'notes']);
    @endphp

    <div class="max-w-6xl mx-auto space-y-6" x-data="{ show: {{ $openPayment ? 'true' : 'false' }}, taxId: '{{ old('landlord_tax_id') }}' }">
        @include('books._nav')

        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            @include('books._year')
            <div class="flex flex-wrap gap-2">
                @if($payable->isNotEmpty())
                    <x-button type="button" @click="taxId = ''; show = true">Record a payment</x-button>
                @endif
                @include('books._exports')
            </div>
        </div>

        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
            <div class="flex flex-col gap-1 px-6 py-5 border-b border-gray-100 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-gray-900">{{ $label }}</h2>
                    <p class="text-sm text-gray-500">{{ $fyFrom->format('d M Y') }} to {{ $fyTo->format('d M Y') }} · {{ $landlord->taxpayer_type === 'company' ? 'Company' : 'Individual' }} taxpayer{{ $landlord->tin ? ' · TIN '.$landlord->tin : '' }}</p>
                </div>
                <p class="text-sm text-gray-500">Gross rental income <span class="font-semibold text-gray-900">{{ $ugx($result['gross_rental']) }}</span></p>
            </div>

            @if($lines->isEmpty())
                <div class="px-6 py-12 text-center">
                    <p class="text-sm text-gray-600">No taxes or levies are switched on.</p>
                    <a href="{{ route('books.settings') }}" class="mt-3 inline-flex items-center rounded-xl bg-navy-800 px-4 py-2 text-sm font-medium text-white hover:bg-navy-700 transition">Choose the taxes that apply to you</a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-100">
                                <th class="px-6 pt-4 pb-2 text-left font-semibold">Tax / levy</th>
                                <th class="px-6 pt-4 pb-2 text-right font-semibold w-36">Due</th>
                                <th class="px-6 pt-4 pb-2 text-right font-semibold w-36">Paid</th>
                                <th class="px-6 pt-4 pb-2 text-right font-semibold w-36">Balance</th>
                                <th class="px-4 pt-4 pb-2 w-24"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($lines as $line)
                                @php $balance = round($line['due'] - $line['paid'], 2); @endphp
                                <tr class="align-top">
                                    <td class="px-6 py-3">
                                        <p class="font-medium text-gray-900">{{ $line['label'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $line['authority'] }}{{ $line['tax']->template->deductible ? ' · deductible expense' : '' }}</p>
                                        <p class="mt-0.5 text-xs text-gray-400">{{ $line['working'] }}</p>
                                    </td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap text-gray-900">{{ $n($line['due']) }}</td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap text-gray-900">{{ $n($line['paid']) }}</td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap font-semibold {{ $balance > 0.005 ? 'text-orange-600' : ($balance < -0.005 ? 'text-blue-700' : 'text-green-700') }}">
                                        {{ $balance > 0.005 ? $n($balance) : ($balance < -0.005 ? 'Over by '.$n(-$balance) : 'Paid') }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="button" @click="taxId = '{{ $line['tax']->id }}'; show = true" class="text-xs font-medium text-orange-600 hover:text-orange-700">Record payment</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-900 font-bold bg-orange-50/50">
                                <td class="px-6 py-3">Total</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">{{ $n($lines->sum('due')) }}</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">{{ $n($lines->sum('paid')) }}</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">{{ $n($lines->sum('due') - $lines->sum('paid')) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="px-6 py-4 space-y-1">
                    <p class="text-xs text-gray-400">Gross rental income is rent received (including rent paid in advance) and deposit deductions; utility recharges are left out. Property rates, ground rent and licences are deductible expenses for companies.</p>
                    <p class="text-xs text-gray-400">These are estimates to help you file. Rates are {{ config('app.name') }}'s defaults unless you changed them in <a href="{{ route('books.settings') }}" class="underline hover:text-orange-600">Settings</a>; confirm with URA and your local council.</p>
                </div>
            @endif
        </section>

        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">Payments for {{ $label }}</h2>
                <p class="text-xs text-gray-500">Record what you pay to URA and your council, so you can see what's left and so deductible levies count as expenses.</p>
            </div>
            @if($payments->isEmpty())
                <p class="px-6 py-8 text-center text-sm text-gray-500">No payments recorded for {{ $label }}.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-gray-500 border-b border-gray-100">
                                <th class="px-6 pt-3 pb-2 text-left font-semibold">Paid on</th>
                                <th class="px-6 pt-3 pb-2 text-left font-semibold">Tax / levy</th>
                                <th class="px-6 pt-3 pb-2 text-left font-semibold">Reference</th>
                                <th class="px-6 pt-3 pb-2 text-right font-semibold">Amount</th>
                                <th class="px-4 pt-3 pb-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($payments as $p)
                                <tr>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-gray-700">{{ $p->paid_on->format('d M Y') }}</td>
                                    <td class="px-6 py-2.5 text-gray-900">{{ $p->landlordTax?->label() ?? '—' }}@if($p->notes)<span class="block text-xs text-gray-400">{{ $p->notes }}</span>@endif</td>
                                    <td class="px-6 py-2.5 text-gray-600">{{ $p->reference ?: '—' }}</td>
                                    <td class="px-6 py-2.5 text-right whitespace-nowrap font-medium text-gray-900">{{ number_format((float) $p->amount) }}</td>
                                    <td class="px-4 py-2.5 text-right">
                                        <form method="POST" action="{{ route('books.tax-payments.destroy', $p->id) }}" @submit.prevent="$store.confirm.open('Remove this tax payment?', $el)">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @if($payable->isNotEmpty())
            <x-modal title="Record a tax payment">
                <form method="POST" action="{{ route('books.tax-payments.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="fiscal_year" value="{{ $year }}">
                    <div>
                        <x-label>Tax / levy</x-label>
                        <x-select name="landlord_tax_id" x-model="taxId" required>
                            <option value="">Choose…</option>
                            @foreach($payable as $tax)
                                <option value="{{ $tax->id }}">{{ $tax->label() }}{{ $tax->is_enabled ? '' : ' (switched off)' }}</option>
                            @endforeach
                        </x-select>
                        @error('landlord_tax_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <p class="text-xs text-gray-500">For {{ $label }}.</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-label>Amount (UGX)</x-label>
                            <x-money-input name="amount" :value="old('amount')" required />
                            @error('amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <x-label>Paid on</x-label>
                            <x-input type="date" name="paid_on" value="{{ old('paid_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required />
                            @error('paid_on')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div>
                        <x-label>Reference <span class="font-normal text-gray-400">(PRN, receipt number)</span></x-label>
                        <x-input name="reference" value="{{ old('reference') }}" maxlength="100" />
                        @error('reference')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <x-label>Notes</x-label>
                        <x-input name="notes" value="{{ old('notes') }}" maxlength="500" />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="show = false" class="rounded-xl px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</button>
                        <x-button type="submit">Record payment</x-button>
                    </div>
                </form>
            </x-modal>
        @endif
    </div>
</x-app-layout>
