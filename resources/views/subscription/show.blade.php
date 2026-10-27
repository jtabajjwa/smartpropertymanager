<x-app-layout>
    <x-slot name="header">Subscription</x-slot>

    @php
        $periodEnd = $landlord->current_period_end;
        $expired = $periodEnd && $periodEnd->isPast() && ! $periodEnd->isToday();
        $statusLabel = match (true) {
            $landlord->subscription_status === 'active' && $expired => ['Expired', 'red'],
            $landlord->subscription_status === 'active' => ['Active', 'green'],
            $landlord->subscription_status === 'trialing' => ['Free trial', 'blue'],
            default => [ucfirst(str_replace('_', ' ', $landlord->subscription_status)), 'gray'],
        };
        $statusColors = ['succeeded' => 'green', 'pending' => 'orange', 'indeterminate' => 'orange', 'failed' => 'red'];
        $statusText = ['succeeded' => 'Paid', 'pending' => 'Awaiting approval', 'indeterminate' => 'Being confirmed', 'failed' => 'Failed'];
        $firstPlan = $plans->first();
        $defaultCycle = $landlord->billing_cycle ?: 'monthly';
        $defaultPlanId = $plans->firstWhere('id', $landlord->plan_id)['id'] ?? $firstPlan['id'] ?? null;
    @endphp

    <div class="py-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Current status --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-semibold text-gray-900">Your Primora subscription</h3>
                            <x-badge :color="$statusLabel[1]">{{ $statusLabel[0] }}</x-badge>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">
                            @if($landlord->plan)
                                {{ $landlord->plan->name }} · {{ \App\Services\SubscriptionBilling::cycleLabel($landlord->billing_cycle) }} billing ·
                            @endif
                            @if($landlord->max_units !== null)
                                {{ $units }} of {{ $landlord->max_units }} units used
                                @if($remaining === 0)<span class="text-orange-600 font-medium"> · limit reached: upgrade below to add more units</span>@endif
                            @else
                                {{ $units }} {{ Str::plural('unit', $units) }} managed
                            @endif
                        </p>
                    </div>
                    <div class="sm:text-right">
                        @if($periodEnd)
                            <p class="text-xs uppercase tracking-wide text-gray-500">{{ $expired ? 'Expired on' : 'Paid until' }}</p>
                            <p class="text-xl font-bold {{ $expired ? 'text-red-600' : 'text-gray-900' }}">{{ $periodEnd->format('d M Y') }}</p>
                            @unless($expired)
                                <p class="text-xs text-gray-500">{{ (int) today()->diffInDays($periodEnd) }} days left</p>
                            @endunless
                        @else
                            <p class="text-sm text-gray-500">No payments yet</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Payment awaiting approval: live status --}}
            @if($awaiting)
                <div x-data="paymentStatus('{{ route('subscription.payment-status', $awaiting->reference) }}', '{{ $awaiting->status }}')"
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
                                    <p class="mt-1 text-sm text-gray-700">A prompt for <strong>UGX {{ number_format((float) $awaiting->amount) }}</strong> has been sent. Enter your mobile money PIN to approve it. This page updates by itself.</p>
                                    <p class="mt-2 text-xs text-gray-500">Reference {{ $awaiting->reference }}<span x-show="status === 'indeterminate'"> · the network is still confirming this payment</span></p>
                                </div>
                            </template>
                            <template x-if="status === 'succeeded'">
                                <div>
                                    <p class="font-semibold text-gray-900">Payment received. Thank you!</p>
                                    <p class="mt-1 text-sm text-gray-700">Your subscription is active<span x-show="periodEnd"> until <strong x-text="periodEnd"></strong></span>.<span x-show="receipt"> Receipt <span x-text="receipt"></span>.</span></p>
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

            {{-- Pay --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">{{ $landlord->subscription_status === 'active' && ! $expired ? 'Renew, upgrade or change your plan' : 'Choose a plan and pay' }}</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Pay with MTN Mobile Money or Airtel Money. Paying early adds the time to the end of your current period.</p>
                </div>

                @if(! $available)
                    <div class="px-6 py-8 text-center text-sm text-gray-600">Mobile money payments aren't available yet. Please contact Primora to renew your subscription.</div>
                @elseif($plans->isEmpty())
                    <div class="px-6 py-8 text-center text-sm text-gray-600">No subscription plans are available for {{ $units }} units. Please contact Primora.</div>
                @else
                    <form method="POST" action="{{ route('subscription.pay') }}" class="p-6 space-y-6"
                          x-data="{
                              plans: {{ Js::from($plans) }},
                              cycles: {{ Js::from(collect($cycles)->map(fn ($c) => $c['label'])) }},
                              units: {{ $units }},
                              planId: {{ (int) old('plan_id', $defaultPlanId) }},
                              tierId: {{ Js::from(old('tier_id') !== null ? (int) old('tier_id') : null) }},
                              cycle: '{{ old('cycle', $defaultCycle) }}',
                              submitting: false,
                              init() {
                                  if (this.tierId === null) this.tierId = this.plan ? this.plan.default_tier : null;
                                  this.$watch('planId', () => { this.tierId = this.plan ? this.plan.default_tier : null; });
                              },
                              get plan() { return this.plans.find(p => p.id === Number(this.planId)) },
                              get tier() { return this.plan ? (this.plan.tiers.find(t => t.id === (this.plan.tiered ? Number(this.tierId) : null)) || null) : null },
                              get amount() { return this.tier && this.tier.eligible ? (this.tier.prices[this.cycle] || null) : null },
                              fromPrice(p) {
                                  const prices = p.tiers.filter(t => t.eligible).map(t => t.prices[this.cycle]).filter(Boolean);
                                  return prices.length ? Math.min(...prices) : null;
                              },
                              per() { return { monthly: 'month', quarterly: 'quarter', annual: 'year' }[this.cycle] },
                              fmt(n) { return Number(n).toLocaleString('en-US') },
                          }"
                          @submit="submitting = true">
                        @csrf
                        <input type="hidden" name="tier_id" :value="plan && plan.tiered ? tierId : ''">

                        {{-- Cycle --}}
                        <div>
                            <x-label>Billing cycle</x-label>
                            <div class="inline-grid grid-cols-3 gap-1 p-1 rounded-xl bg-gray-100">
                                @foreach($cycles as $key => $c)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="cycle" value="{{ $key }}" x-model="cycle" class="sr-only">
                                        <span class="block px-4 py-2 rounded-lg text-sm font-medium transition" :class="cycle === '{{ $key }}' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'">{{ $c['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Plans --}}
                        <div x-show="plans.length > 1">
                            <x-label>Plan</x-label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <template x-for="p in plans" :key="p.id">
                                    <label class="relative flex cursor-pointer rounded-xl border p-4 transition" :class="Number(planId) === p.id ? 'border-orange-500 ring-1 ring-orange-500 bg-orange-50/40' : 'border-gray-200 hover:border-gray-300'">
                                        <input type="radio" name="plan_id" :value="p.id" x-model="planId" class="sr-only">
                                        <div class="flex-1">
                                            <p class="text-sm font-semibold text-gray-900" x-text="p.name"></p>
                                            <p class="mt-1 text-sm text-gray-600" x-show="fromPrice(p)">
                                                <span x-text="p.tiered ? 'From ' : ''"></span><span class="font-semibold text-gray-900" x-text="'UGX ' + fmt(fromPrice(p))"></span> / <span x-text="per()"></span>
                                            </p>
                                            <p class="mt-1 text-xs text-gray-500" x-text="p.tiered ? 'Priced by number of units' : 'Any number of units'"></p>
                                            <p class="mt-2 text-xs text-gray-600" x-show="p.missing.length === 0">Includes every feature</p>
                                            <ul class="mt-2 space-y-0.5 text-xs" x-show="p.missing.length > 0">
                                                <template x-for="f in p.features" :key="f"><li class="text-gray-700">✓ <span x-text="f"></span></li></template>
                                                <template x-for="f in p.missing" :key="f"><li class="text-gray-400">– <span x-text="f"></span></li></template>
                                            </ul>
                                        </div>
                                    </label>
                                </template>
                            </div>
                        </div>
                        <template x-if="plans.length === 1"><input type="hidden" name="plan_id" :value="planId"></template>

                        {{-- Units (tier) --}}
                        <div x-show="plan && plan.tiered">
                            <x-label>Number of units</x-label>
                            <p class="-mt-0.5 mb-2 text-xs text-gray-500">You currently have <strong x-text="units"></strong> <span x-text="units === 1 ? 'unit' : 'units'"></span>. Choose a tier that covers them, or a larger one if you plan to add more. You won't be able to add units beyond your tier without upgrading.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                <template x-for="t in (plan ? plan.tiers : [])" :key="t.id ?? 'flat'">
                                    <label class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 transition"
                                           :class="!t.eligible ? 'border-gray-100 bg-gray-50 text-gray-400 cursor-not-allowed' : (Number(tierId) === t.id ? 'border-orange-500 ring-1 ring-orange-500 bg-orange-50/40 cursor-pointer' : 'border-gray-200 hover:border-gray-300 cursor-pointer')">
                                        <input type="radio" :value="t.id" x-model.number="tierId" :disabled="!t.eligible" class="sr-only">
                                        <span>
                                            <span class="block text-sm font-medium" :class="t.eligible ? 'text-gray-900' : ''" x-text="t.label"></span>
                                            <span class="block text-xs" x-show="!t.eligible" x-text="'Too small: you have ' + units"></span>
                                        </span>
                                        <span class="text-sm whitespace-nowrap" :class="t.eligible ? 'font-semibold text-gray-900' : ''" x-text="t.prices[cycle] ? 'UGX ' + fmt(t.prices[cycle]) : '—'"></span>
                                    </label>
                                </template>
                            </div>
                        </div>

                        {{-- Phone + summary --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 items-end">
                            <div>
                                <x-label :required="true">Mobile money number</x-label>
                                <x-input name="phone" type="tel" inputmode="tel" value="{{ old('phone', $defaultPhone) }}" placeholder="0772 123 456" required />
                                <p class="mt-1 text-xs text-gray-500">MTN or Airtel. You'll get a prompt on this phone to approve with your PIN.</p>
                            </div>
                            <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                                <p class="text-xs uppercase tracking-wide text-gray-500">You'll pay</p>
                                <p class="mt-1 text-2xl font-bold text-gray-900" x-text="amount ? 'UGX ' + fmt(amount) : '—'"></p>
                                <p class="text-xs text-gray-500" x-text="plan ? [plan.name, plan.tiered && tier ? tier.label : null, cycles[cycle]].filter(Boolean).join(' · ') : ''"></p>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <x-button type="submit" x-bind:disabled="!amount || submitting">
                                <span x-show="!submitting">Send payment request</span>
                                <span x-show="submitting" x-cloak>Sending…</span>
                            </x-button>
                        </div>
                    </form>
                @endif
            </div>

            {{-- History --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">Payment history</h3>
                </div>
                @if($payments->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-gray-500">No subscription payments yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Plan</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Phone</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($payments as $payment)
                                    @php $c = $payment->context ?? []; @endphp
                                    <tr>
                                        <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $payment->created_at->format('d M Y, H:i') }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-900">
                                            {{ $c['plan_name'] ?? '—' }} · {{ \App\Services\SubscriptionBilling::cycleLabel($c['cycle'] ?? null) }}
                                            @if(! empty($c['period_end']))<span class="block text-xs text-gray-500">Covers to {{ \Carbon\Carbon::parse($c['period_end'])->format('d M Y') }}</span>@endif
                                        </td>
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
                @endif
            </div>
        </div>
    </div>

</x-app-layout>
