<x-staff-layout>
    <x-slot name="header">Subscription · {{ $landlord->name }}</x-slot>

    @php
        [$stateLabel, $stateColor] = \App\Services\SubscriptionBilling::STATES[$landlord->subscriptionState()];
        $end = $landlord->current_period_end;
        $payStatusColors = ['succeeded' => 'green', 'pending' => 'orange', 'indeterminate' => 'orange', 'failed' => 'red'];
        $fieldLabels = ['subscription_status' => 'Status', 'plan_id' => 'Plan', 'billing_cycle' => 'Billing cycle', 'max_units' => 'Unit limit', 'current_period_end' => 'Paid until', 'grace_ends_at' => 'Grace ends'];
        $fmt = function ($field, $value) use ($planNames) {
            if ($field === 'max_units') return ($value === null || $value === '') ? 'No limit' : $value.' units';
            if ($value === null || $value === '') return '—';
            return match ($field) {
                'subscription_status' => \App\Services\SubscriptionBilling::STATUSES[$value] ?? ucfirst($value),
                'plan_id' => $planNames[$value] ?? "Plan #{$value}",
                'billing_cycle' => \App\Services\SubscriptionBilling::cycleLabel($value),
                'current_period_end', 'grace_ends_at' => \Carbon\Carbon::parse($value)->format('d M Y'),
                default => $value,
            };
        };
        $defaultPlan = old('plan_id', $landlord->plan_id ?? $plans->first()?->id);
        $defaultCycle = old('cycle', $landlord->billing_cycle ?? 'monthly');
    @endphp

    <div class="max-w-6xl mx-auto">
        <a href="{{ route('staff.subscriptions.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            All subscriptions
        </a>

        {{-- Summary --}}
        <div class="mt-3 mb-6 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-semibold text-gray-900">{{ $landlord->name }}</h2>
                        <x-badge :color="$stateColor">{{ $stateLabel }}</x-badge>
                        @if($landlord->status !== 'active')<x-badge color="red">Account {{ $landlord->status }}</x-badge>@endif
                    </div>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $landlord->contact_email }} · {{ $landlord->contact_phone }} ·
                        <a href="{{ route('staff.landlords.show', $landlord) }}" class="text-orange-600 hover:text-orange-700">Landlord workspace</a>
                    </p>
                </div>
                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-8 gap-y-3 text-sm">
                    <div><dt class="text-xs text-gray-500">Plan</dt><dd class="font-medium text-gray-900">{{ $landlord->plan?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Billing</dt><dd class="font-medium text-gray-900">{{ $landlord->billing_cycle ? \App\Services\SubscriptionBilling::cycleLabel($landlord->billing_cycle) : '—' }}</dd></div>
                    <div>
                        <dt class="text-xs text-gray-500">Paid until</dt>
                        <dd class="font-medium {{ $end && $end->lt(today()) ? 'text-red-600' : 'text-gray-900' }}">{{ $end?->format('d M Y') ?? 'No end date' }}</dd>
                    </div>
                    <div><dt class="text-xs text-gray-500">Units used</dt><dd class="font-medium {{ $unitLimitRemaining === 0 ? 'text-orange-600' : 'text-gray-900' }}">{{ $units }}{{ $landlord->max_units !== null ? ' of '.$landlord->max_units : '' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Worth per month</dt><dd class="font-medium text-gray-900">{{ $monthlyValue !== null ? 'UGX '.number_format($monthlyValue) : '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Total paid</dt><dd class="font-medium text-gray-900">UGX {{ number_format((float) $landlord->subscription_paid) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Last payment</dt><dd class="font-medium text-gray-900">{{ $landlord->last_paid_at ? \Carbon\Carbon::parse($landlord->last_paid_at)->format('d M Y') : '—' }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            @staffcan('subscriptions.record_payment'){{-- Record offline payment --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6"
                 x-data="{
                     options: {{ Js::from($tierOptions) }},
                     planId: '{{ $defaultPlan }}',
                     cycle: '{{ $defaultCycle }}',
                     tierId: {{ Js::from(old('tier_id') !== null ? (int) old('tier_id') : null) }},
                     amount: '{{ old('amount') }}',
                     get opt() { return this.options[this.planId] || { tiered: false, tiers: [] } },
                     get tier() { return this.opt.tiers.find(t => t.id === (this.opt.tiered ? Number(this.tierId) : null)) || null },
                     get listPrice() { return this.tier && this.tier.eligible ? (this.tier.prices[this.cycle] || null) : null },
                     syncAmount() { if (this.listPrice) this.amount = this.listPrice },
                     planChanged() { this.tierId = this.opt.default_tier; this.syncAmount() },
                     init() { if (this.tierId === null) this.tierId = this.opt.default_tier; if (!this.amount) this.syncAmount() },
                 }">
                <h3 class="text-base font-semibold text-gray-900">Record an offline payment</h3>
                <p class="mt-0.5 text-sm text-gray-500">Cash, bank transfer or mobile money sent directly. Extends the subscription like a mobile money payment and appears under Payments.</p>

                <form method="POST" action="{{ route('staff.subscriptions.payments.store', $landlord) }}" class="mt-5 space-y-4">
                    @csrf
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-label :required="true">Plan</x-label>
                            <x-select name="plan_id" x-model="planId" @change="planChanged()" required>
                                @foreach($plans as $plan)
                                    <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-label :required="true">Period paid for</x-label>
                            <x-select name="cycle" x-model="cycle" @change="syncAmount()" required>
                                @foreach(\App\Services\SubscriptionBilling::CYCLES as $key => $c)
                                    <option value="{{ $key }}">{{ $c['label'] }} ({{ $c['months'] }} {{ Str::plural('month', $c['months']) }})</option>
                                @endforeach
                            </x-select>
                        </div>
                    </div>
                    <div x-show="opt.tiered">
                        <x-label :required="true">Unit tier (becomes the unit limit)</x-label>
                        <select name="tier_id" x-model.number="tierId" @change="syncAmount()" :disabled="!opt.tiered"
                                class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-900 focus:bg-gray-50 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition">
                            <template x-for="t in opt.tiers" :key="t.id">
                                <option :value="t.id" :disabled="!t.eligible" :selected="t.id === tierId" x-text="t.label + (t.eligible ? '' : ' (too small: {{ $units }} units in use)')"></option>
                            </template>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-label :required="true">Amount received (UGX)</x-label>
                            <x-input type="number" name="amount" x-model="amount" min="1" step="1" required />
                            <p class="mt-1 text-xs text-gray-500" x-show="listPrice" x-text="listPrice ? 'List price: UGX ' + Number(listPrice).toLocaleString('en-US') + (tier && opt.tiered ? ' (' + tier.label + ')' : '') : ''"></p>
                        </div>
                        <div>
                            <x-label :required="true">Received on</x-label>
                            <x-input type="date" name="received_on" value="{{ old('received_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-label :required="true">Method</x-label>
                            <x-select name="method" required>
                                @foreach(\App\Payments\PaymentService::OFFLINE_METHODS as $key => $label)
                                    <option value="{{ $key }}" @selected(old('method') === $key)>{{ $label }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-label>Receipt / bank reference</x-label>
                            <x-input name="reference" value="{{ old('reference') }}" maxlength="100" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-label>Payer's phone</x-label>
                            <x-input name="payer_phone" value="{{ old('payer_phone') }}" placeholder="Optional" />
                        </div>
                        <div>
                            <x-label>Note</x-label>
                            <x-input name="note" value="{{ old('note') }}" maxlength="300" placeholder="Optional" />
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <x-button type="submit" @click="if (!confirm('Record UGX ' + Number(amount).toLocaleString('en-US') + ' and extend the subscription?')) $event.preventDefault()">Record payment</x-button>
                    </div>
                </form>
            </div>@endstaffcan

            @staffcan('subscriptions.adjust'){{-- Adjust --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-base font-semibold text-gray-900">Adjust subscription</h3>
                <p class="mt-0.5 text-sm text-gray-500">Set a trial end, give an extension, cancel, or correct a mistake. Every change is kept in the history below, with your reason.</p>

                <form method="POST" action="{{ route('staff.subscriptions.update', $landlord) }}" class="mt-5 space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-label :required="true">Status</x-label>
                            <x-select name="subscription_status" required>
                                @foreach(\App\Services\SubscriptionBilling::STATUSES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('subscription_status', $landlord->subscription_status) === $key)>{{ $label }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-label>Paid until / trial ends</x-label>
                            <x-input type="date" name="current_period_end" value="{{ old('current_period_end', $end?->toDateString()) }}" />
                        </div>
                    </div>
                    <div>
                        <x-label>Unit limit</x-label>
                        <x-input type="number" name="max_units" min="1" value="{{ old('max_units', $landlord->max_units) }}" placeholder="No limit" />
                        <p class="mt-1 text-xs text-gray-500">How many units they may have ({{ $units }} in use). Leave blank for no limit.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-label>Plan</x-label>
                            <x-select name="plan_id">
                                <option value="">None</option>
                                @foreach($allPlans as $plan)
                                    <option value="{{ $plan->id }}" @selected((string) old('plan_id', $landlord->plan_id) === (string) $plan->id)>{{ $plan->name }}{{ $plan->is_active ? '' : ' (inactive)' }}</option>
                                @endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-label>Billing cycle</x-label>
                            <x-select name="billing_cycle">
                                <option value="">None</option>
                                @foreach(\App\Services\SubscriptionBilling::CYCLES as $key => $c)
                                    <option value="{{ $key }}" @selected(old('billing_cycle', $landlord->billing_cycle) === $key)>{{ $c['label'] }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    </div>
                    <div>
                        <x-label :required="true">Reason</x-label>
                        <x-input name="reason" value="{{ old('reason') }}" maxlength="300" placeholder="e.g. 14-day trial extension agreed with the landlord" required />
                    </div>
                    <div class="flex justify-end">
                        <x-button type="submit" variant="secondary">Save changes</x-button>
                    </div>
                </form>
            </div>@endstaffcan
        </div>

        {{-- Payments --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 mb-6">
            <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-base font-semibold text-gray-900">Subscription payments</h3></div>
            @if($payments->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">No subscription payments yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">For</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">How</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($payments as $payment)
                                @php $pc = $payment->context ?? []; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">{{ ($payment->completed_at ?? $payment->created_at)->format('d M Y') }}</td>
                                    <td class="px-6 py-3 text-sm"><a href="{{ route('staff.payments.show', $payment) }}" class="font-mono text-gray-900 hover:text-orange-600">{{ $payment->reference }}</a></td>
                                    <td class="px-6 py-3 text-sm text-gray-700">
                                        {{ $pc['plan_name'] ?? '—' }} · {{ \App\Services\SubscriptionBilling::cycleLabel($pc['cycle'] ?? null) }}
                                        @if(! empty($pc['period_end']))<span class="block text-xs text-gray-400">{{ \Carbon\Carbon::parse($pc['period_start'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($pc['period_end'])->format('d M Y') }}</span>@endif
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600">
                                        @if($payment->gateway === 'manual')
                                            {{ \App\Payments\PaymentService::OFFLINE_METHODS[$pc['method'] ?? ''] ?? 'Offline' }}
                                            <span class="block text-xs text-gray-400">recorded by {{ $payment->initiatedByStaffUser?->name ?? 'staff' }}</span>
                                        @else
                                            {{ strtoupper($payment->gateway) }} · {{ $payment->displayMsisdn() }}
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-900 text-right whitespace-nowrap">UGX {{ number_format((float) $payment->amount) }}</td>
                                    <td class="px-6 py-3"><x-badge :color="$payStatusColors[$payment->status] ?? 'gray'">{{ ucfirst($payment->status) }}</x-badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Change history --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200"><h3 class="text-base font-semibold text-gray-900">Subscription history</h3></div>
            @if($history->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-gray-500">No changes recorded yet.</p>
            @else
                <ol class="divide-y divide-gray-100">
                    @foreach($history as $audit)
                        <li class="px-6 py-4">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-sm font-medium text-gray-900">
                                    {{ $audit->staffUser ? $audit->staffUser->name.' (Primora staff)' : ($audit->user?->name ?? 'System') }}
                                </p>
                                <p class="text-xs text-gray-500">{{ $audit->created_at->format('d M Y, H:i') }}</p>
                            </div>
                            @if($audit->note)<p class="mt-0.5 text-sm text-gray-600">{{ $audit->note }}</p>@endif
                            <ul class="mt-2 space-y-0.5 text-xs text-gray-600">
                                @foreach(array_intersect_key($audit->new_values ?? [], $fieldLabels) as $field => $new)
                                    <li><span class="text-gray-500">{{ $fieldLabels[$field] }}:</span> {{ $fmt($field, $audit->old_values[$field] ?? null) }} → <span class="font-medium text-gray-900">{{ $fmt($field, $new) }}</span></li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
</x-staff-layout>
