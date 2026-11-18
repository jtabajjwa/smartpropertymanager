<x-staff-layout>
    <x-slot name="header">Reconciliation</x-slot>

    @php
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $signed = fn ($v) => ($v > 0 ? '+' : ($v < 0 ? '−' : '')).number_format(abs((float) $v));
    @endphp

    <div class="max-w-7xl mx-auto space-y-6">
        @include('staff.books._nav')

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-gray-600">
                The Yo! pool balance is checked against the books every hour, and money held must cover what landlords are owed.
                Staff who record in the books are emailed when something needs attention.
                @if($latest)<span class="text-gray-400">Last checked {{ $latest->checked_at->diffForHumans() }}{{ $latest->checkedBy ? ' by '.$latest->checkedBy->name : '' }}.</span>@endif
            </p>
            <form method="POST" action="{{ route('staff.books.reconciliation.check') }}" class="shrink-0">
                @csrf
                <x-button type="submit">Check now</x-button>
            </form>
        </div>

        @if(! $latest)
            <div class="bg-white rounded-2xl border border-gray-200 px-6 py-12 text-center text-sm text-gray-500">No checks yet. Click <strong>Check now</strong>, or wait for the hourly check.</div>
        @else
            @if($latest->problems)
                <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
                    <p class="font-semibold">Needs attention</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">@foreach($latest->problems as $p)<li>{{ $p }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                {{-- Yo! against the books --}}
                <section class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-900">Yo! Payments pool</h2>
                        @php [$label, $color] = $latest->statusLabel(); @endphp
                        <x-badge :color="$color">{{ $label }}</x-badge>
                    </div>
                    @if($latest->status === \App\Models\BalanceCheck::FAILED)
                        <p class="px-6 py-4 text-sm text-gray-700">The last check couldn't reach Yo!: {{ $latest->message }}</p>
                        @if($lastGood)
                            <p class="px-6 pb-4 -mt-2 text-xs text-gray-500">Last successful check {{ $lastGood->checked_at->format('d M Y, H:i') }}: difference {{ $signed($lastGood->difference) }}.</p>
                        @endif
                    @else
                        <dl class="divide-y divide-gray-100 text-sm">
                            <div class="px-6 py-3 flex justify-between gap-4"><dt class="text-gray-600">Books: Yo! Payments pool</dt><dd class="font-medium text-gray-900">{{ $ugx($latest->book_balance) }}</dd></div>
                            <div class="px-6 py-3 flex justify-between gap-4"><dt class="text-gray-600">Less payouts Yo! is still sending <span class="block text-xs text-gray-400">Taken from the pool; the books record them when they complete.</span></dt><dd class="font-medium text-gray-900">−{{ number_format($latest->in_flight) }}</dd></div>
                            <div class="px-6 py-3 flex justify-between gap-4 bg-gray-50/60"><dt class="font-medium text-gray-900">Yo! should hold</dt><dd class="font-semibold text-gray-900">{{ $ugx($latest->book_balance - $latest->in_flight) }}</dd></div>
                            <div class="px-6 py-3 flex justify-between gap-4">
                                <dt class="text-gray-600">Yo! reports
                                    @if($latest->wallets)<span class="block text-xs text-gray-400">@foreach($latest->wallets as $code => $amount){{ $code }}: {{ number_format($amount) }}@if(! $loop->last) · @endif @endforeach</span>@endif
                                </dt>
                                <dd class="font-medium text-gray-900">{{ $ugx($latest->partner_balance) }}</dd>
                            </div>
                            <div class="px-6 py-3 flex justify-between gap-4"><dt class="font-semibold text-gray-900">Difference</dt><dd class="font-bold {{ $latest->status === 'matched' ? 'text-green-700' : 'text-red-600' }}">{{ $signed($latest->difference) }}</dd></div>
                        </dl>
                    @endif
                </section>

                {{-- Safeguarding --}}
                @php $cover = $latest->cover(); @endphp
                <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <h2 class="text-base font-semibold text-gray-900">Landlord {{ $latest->partner_funds > 0 ? 'and partner ' : '' }}funds covered</h2>
                        <x-badge :color="$cover !== null && $cover >= 0 ? 'green' : 'red'">{{ $cover !== null && $cover >= 0 ? 'Covered' : 'Shortfall' }}</x-badge>
                    </div>
                    <dl class="divide-y divide-gray-100 text-sm">
                        <div class="px-6 py-3 flex justify-between gap-4"><dt class="text-gray-600">Money held <span class="block text-xs text-gray-400">{{ $latest->status === 'failed' ? 'Yo! from the books' : 'Yo! as reported' }}, plus bank, wallet and cash from the books</span></dt><dd class="font-medium text-gray-900">{{ $ugx($latest->money_held) }}</dd></div>
                        <div class="px-6 py-3 flex justify-between gap-4"><dt class="text-gray-600">Owed to landlords</dt><dd class="font-medium text-gray-900">{{ $ugx($latest->landlord_funds) }}</dd></div>
                        @if($latest->partner_funds > 0)<div class="px-6 py-3 flex justify-between gap-4"><dt class="text-gray-600">Owed to partners</dt><dd class="font-medium text-gray-900">{{ $ugx($latest->partner_funds) }}</dd></div>@endif
                        <div class="px-6 py-3 flex justify-between gap-4 bg-gray-50/60"><dt class="font-semibold text-gray-900">{{ $cover >= 0 ? 'Primora\'s own money' : 'Shortfall' }}</dt><dd class="font-bold {{ $cover >= 0 ? 'text-gray-900' : 'text-red-600' }}">{{ $ugx(abs($cover)) }}</dd></div>
                    </dl>
                </section>
            </div>

            {{-- Explaining a difference --}}
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h2 class="text-base font-semibold text-gray-900">If there is a difference</h2>
                </div>
                <div class="grid gap-6 px-6 py-4 text-sm text-gray-700 md:grid-cols-2">
                    <div class="space-y-2">
                        <p><strong class="text-gray-900">Yo! holds more (+)</strong>: a collection Yo! completed before we heard about it (usually clears within minutes), or money paid into the pool that isn't in the books.</p>
                        <p><strong class="text-gray-900">Yo! holds less (−)</strong>: a Yo! charge different from the one recorded, a payout Yo! sent that's still pending here, or a withdrawal made outside Primora.</p>
                        <p class="text-gray-500">Correct the books with a journal entry or a transfer, and attach Yo!'s statement to it. A difference shown on one check only is not reported.</p>
                    </div>
                    <div class="space-y-2">
                        <p class="flex justify-between gap-4"><span>Collections still waiting for an answer</span>
                            <a href="{{ route('staff.payments.index') }}" class="font-medium text-orange-600 hover:text-orange-700">{{ $openCollections->count() }} · {{ $ugx($openCollections->sum('amount')) }}</a></p>
                        <p class="flex justify-between gap-4"><span>Payouts Yo! is sending now</span><span class="font-medium text-gray-900">{{ $ugx($inFlight) }}</span></p>
                    </div>
                </div>
            </section>
        @endif

        {{-- Follow-ups --}}
        @if($followUps['payouts']->isNotEmpty() || $followUps['collections']->isNotEmpty())
            <section class="bg-white rounded-2xl border border-orange-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-orange-100 bg-orange-50">
                    <h2 class="text-base font-semibold text-gray-900">To follow up</h2>
                    <p class="text-xs text-gray-600">Automatic checks haven't settled these. Confirm them on Yo!'s statement or portal.</p>
                </div>
                <ul class="divide-y divide-gray-100 text-sm">
                    @foreach($followUps['payouts'] as $l)
                        <li class="px-6 py-3 flex flex-wrap items-center justify-between gap-2">
                            <span><x-badge color="orange">Payout sending</x-badge> <a href="{{ route('staff.liquidations.show', $l->id) }}" class="font-medium text-gray-900 hover:text-orange-600">{{ $l->reference }}</a> · {{ $l->landlord?->name }} · sent {{ $l->sent_at?->diffForHumans() ?? 'at an unknown time' }}</span>
                            <span class="font-medium">{{ $ugx($l->net_amount) }}</span>
                        </li>
                    @endforeach
                    @foreach($followUps['collections'] as $t)
                        <li class="px-6 py-3 flex flex-wrap items-center justify-between gap-2">
                            <span><x-badge color="gray">Collection unresolved</x-badge> <a href="{{ route('staff.payments.show', $t) }}" class="font-medium text-gray-900 hover:text-orange-600">{{ $t->reference }}</a> · {{ $t->landlord?->name ?? ucfirst($t->purpose) }} · started {{ $t->created_at->diffForHumans() }}</span>
                            <span class="font-medium">{{ $ugx($t->amount) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- History --}}
        @if($checks->isNotEmpty())
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100"><h2 class="text-base font-semibold text-gray-900">Recent checks</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-5 py-2.5 text-left">Checked</th>
                                <th class="px-5 py-2.5 text-right">Yo! reports</th>
                                <th class="px-5 py-2.5 text-right">Yo! should hold</th>
                                <th class="px-5 py-2.5 text-right">Difference</th>
                                <th class="px-5 py-2.5 text-right">Funds cover</th>
                                <th class="px-5 py-2.5 text-left">Result</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach($checks as $c)
                                @php [$label, $color] = $c->statusLabel(); $cv = $c->cover(); @endphp
                                <tr>
                                    <td class="px-5 py-2 whitespace-nowrap text-gray-600">{{ $c->checked_at->format('d M, H:i') }}@if($c->checkedBy)<span class="text-xs text-gray-400"> · {{ $c->checkedBy->name }}</span>@endif</td>
                                    <td class="px-5 py-2 text-right whitespace-nowrap">{{ $c->partner_balance === null ? '—' : number_format($c->partner_balance) }}</td>
                                    <td class="px-5 py-2 text-right whitespace-nowrap">{{ number_format($c->book_balance - $c->in_flight) }}</td>
                                    <td class="px-5 py-2 text-right whitespace-nowrap font-medium {{ $c->status === 'difference' ? 'text-red-600' : 'text-gray-900' }}">{{ $c->difference === null ? '—' : $signed($c->difference) }}</td>
                                    <td class="px-5 py-2 text-right whitespace-nowrap {{ $cv !== null && $cv < 0 ? 'text-red-600 font-medium' : 'text-gray-600' }}">{{ $cv === null ? '—' : $signed($cv) }}</td>
                                    <td class="px-5 py-2 whitespace-nowrap"><x-badge :color="$color">{{ $label }}</x-badge>@if($c->alerted_at)<span class="ml-1 text-xs text-gray-400" title="Staff were emailed">✉</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-staff-layout>
