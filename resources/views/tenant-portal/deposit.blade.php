<x-tenant-layout title="Security deposit" subtitle="What you paid, how it was used, and what is left">
    @php
        $ugx = fn ($v) => 'UGX '.number_format((float) $v);
        $types = \App\Models\SecurityDepositLedger::TYPES;
    @endphp

    <h1 class="text-xl font-bold text-gray-900 mb-4 lg:hidden">My security deposit</h1>

    <div class="space-y-6">
        @forelse($deposits as $d)
            <section class="bg-white rounded-2xl border border-gray-200 lg:shadow-sm overflow-hidden">
                <div class="px-4 lg:px-5 py-3 lg:py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold text-gray-900">{{ trim(($d->lease->unit?->estate?->name ? $d->lease->unit->estate->name.' · ' : '').($d->lease->unit?->name ?? 'Your unit')) }}</h2>
                    <span class="text-xs text-gray-500">Lease {{ $d->lease->number }}{{ $d->lease->status !== 'active' ? ' · ended' : '' }}</span>
                </div>

                {{-- Summary --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-gray-100 border-b border-gray-100">
                    <div class="bg-white px-4 lg:px-5 py-3">
                        <p class="text-[11px] uppercase tracking-wide text-gray-500">Paid</p>
                        <p class="mt-0.5 text-base font-bold text-gray-900">{{ $ugx($d->paid) }}</p>
                        @if($d->agreed > $d->paid)<p class="text-[11px] text-orange-700">of {{ $ugx($d->agreed) }} agreed</p>@endif
                    </div>
                    <div class="bg-white px-4 lg:px-5 py-3">
                        <p class="text-[11px] uppercase tracking-wide text-gray-500">Used</p>
                        <p class="mt-0.5 text-base font-bold {{ $d->used > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $ugx($d->used) }}</p>
                    </div>
                    <div class="bg-white px-4 lg:px-5 py-3">
                        <p class="text-[11px] uppercase tracking-wide text-gray-500">Refunded{{ $d->kept > 0 ? ' / kept' : '' }}</p>
                        <p class="mt-0.5 text-base font-bold text-gray-900">{{ $ugx($d->refunded) }}@if($d->kept > 0) <span class="text-sm font-medium text-purple-700">/ {{ number_format($d->kept) }}</span>@endif</p>
                    </div>
                    <div class="bg-navy-800 text-white px-4 lg:px-5 py-3">
                        <p class="text-[11px] uppercase tracking-wide text-gray-300">Held for you now</p>
                        <p class="mt-0.5 text-base font-bold">{{ $ugx($d->balance) }}</p>
                    </div>
                </div>

                {{-- How it was used --}}
                @if($d->paid > 0)
                    <div class="px-4 lg:px-5 pt-3">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 flex">
                            <div class="h-full bg-red-400" style="width: {{ min(100, $d->used * 100 / $d->paid) }}%" title="Used"></div>
                            <div class="h-full bg-orange-300" style="width: {{ min(100, ($d->refunded + $d->kept) * 100 / $d->paid) }}%" title="Refunded / kept"></div>
                            <div class="h-full bg-green-500" style="width: {{ max(0, min(100, $d->balance * 100 / $d->paid)) }}%" title="Held"></div>
                        </div>
                    </div>
                @endif

                @if($d->rows->isEmpty())
                    <p class="px-4 lg:px-5 py-8 text-center text-sm text-gray-500">Your deposit hasn't been paid yet.</p>
                @else
                    <ul class="divide-y divide-gray-100 mt-2">
                        @foreach($d->rows as $row)
                            @php $e = $row['entry']; @endphp
                            <li class="px-4 lg:px-5 py-3 flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-badge :color="$types[$e->type][1] ?? 'gray'">{{ $e->type === 'deduction' ? ($e->categoryLabel() ?? 'Used') : ($types[$e->type][0] ?? ucfirst($e->type)) }}</x-badge>
                                        <span class="text-xs text-gray-500">{{ $e->date->format('d M Y') }}</span>
                                    </div>
                                    @if($e->notes)
                                        <p class="mt-1 text-sm text-gray-800 break-words">{{ $e->notes }}</p>
                                    @endif
                                    @if($e->attachment)
                                        <a href="{{ Storage::disk('public')->url($e->attachment) }}" target="_blank" hx-boost="false" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-orange-600 hover:text-orange-700">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
                                            View receipt / photo
                                        </a>
                                    @endif
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-sm font-semibold whitespace-nowrap {{ $row['in'] ? 'text-green-700' : 'text-red-600' }}">{{ $row['in'] ? '+' : '−' }}{{ number_format((float) $e->amount) }}</p>
                                    <p class="text-[11px] text-gray-500 whitespace-nowrap">Left: {{ number_format($row['balance']) }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @empty
            <div class="bg-white rounded-2xl border border-gray-200 p-10 text-center">
                <p class="text-sm font-medium text-gray-900">No security deposit</p>
                <p class="mt-1 text-sm text-gray-500">Your tenancy doesn't have a security deposit on record.</p>
            </div>
        @endforelse

        <p class="text-xs text-gray-500">Your landlord holds your deposit and can use it for damage, cleaning or unpaid bills. Each use is shown here with the reason, and you get an SMS. Questions? Report an issue or contact your landlord.</p>
    </div>
</x-tenant-layout>
