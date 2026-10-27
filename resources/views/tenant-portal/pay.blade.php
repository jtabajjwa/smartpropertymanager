<x-tenant-layout title="Make a payment" :subtitle="'Pay '.$landlord->name.' by MTN or Airtel Money'">
    <h1 class="text-xl font-bold text-gray-900 mb-4 lg:hidden">Pay {{ $landlord->name }}</h1>

    @if(! $enabled)
        <div class="bg-white rounded-2xl border border-gray-200 p-6 text-sm text-gray-700 lg:max-w-2xl">
            <p class="font-semibold text-gray-900">Mobile money payments aren't available for your tenancy yet.</p>
            <p class="mt-1">Please pay as agreed with your landlord. Payments they record will show on your account.</p>
        </div>
    @else
        <form method="POST" action="{{ route('tenant.pay') }}" class="space-y-4 lg:space-y-0 lg:grid lg:grid-cols-3 lg:gap-6 lg:items-start"
              x-data="{
                  invoices: {{ Js::from($openInvoices->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'number' => $i->number, 'balance' => (float) $i->balance, 'due' => $i->due_date->format('d M Y')])) }},
                  tiers: {{ Js::from($tiers) }},
                  passFee: {{ $passFee ? 'true' : 'false' }},
                  target: '{{ $advance ? 'advance' : ($selected?->id ?? 'advance') }}',
                  amount: '{{ old('amount', $advance ? '' : ($selected ? (int) $selected->balance : '')) }}',
                  busy: false,
                  init() { this.$watch('target', () => { const i = this.invoice; this.amount = i ? Math.round(i.balance) : ''; }); },
                  get invoice() { return this.invoices.find(i => String(i.id) === String(this.target)) || null },
                  feeFor(a) {
                      const t = this.tiers.find(t => a >= t.min && (t.max === null || a <= t.max));
                      return t ? Math.ceil(t.fixed + a * t.percent / 100) : 0;
                  },
                  get fee() {
                      const a = Number(this.amount) || 0;
                      if (!this.passFee || a <= 0) return 0;
                      // Same as CollectionFees::grossUpFee(): the smallest fee that covers the charge on the total.
                      let fee = this.feeFor(a); const covering = [];
                      for (let i = 0; i < 10; i++) { const next = this.feeFor(a + fee); if (next <= fee) { covering.push(fee); if (next === fee) break; } fee = next; }
                      return covering.length ? Math.min(...covering) : fee;
                  },
                  get total() { return (Number(this.amount) || 0) + this.fee },
                  get tooMuch() { return this.invoice && Number(this.amount) > this.invoice.balance },
                  fmt(n) { return Number(n).toLocaleString('en-US') },
              }"
              @submit="busy = true">
            @csrf
            <input type="hidden" name="invoice_id" :value="target === 'advance' ? '' : target">

            <div class="space-y-4 lg:space-y-6 lg:col-span-2">
            <section class="bg-white rounded-2xl border border-gray-200 p-4 lg:p-6 lg:shadow-sm">
                <h2 class="text-sm font-semibold text-gray-900">What are you paying for?</h2>
                <div class="mt-3 space-y-2">
                    @foreach($openInvoices as $invoice)
                        <label class="flex items-center gap-3 rounded-xl border px-3.5 py-3 cursor-pointer transition" :class="String(target) === '{{ $invoice->id }}' ? 'border-orange-500 bg-orange-50/40 ring-1 ring-orange-500' : 'border-gray-200'">
                            <input type="radio" value="{{ $invoice->id }}" x-model="target" class="text-orange-600 focus:ring-orange-500">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-gray-900 truncate">{{ $invoice->title }}</span>
                                <span class="block text-xs {{ $invoice->due_date->lt(today()) ? 'text-red-600' : 'text-gray-500' }}">{{ $invoice->number }} · due {{ $invoice->due_date->format('d M Y') }}</span>
                                <x-tenant-unit :lease="$invoice->lease" class="mt-1" />
                            </span>
                            <span class="text-sm font-semibold text-gray-900 whitespace-nowrap">{{ number_format($invoice->balance) }}</span>
                        </label>
                    @endforeach
                    <label class="flex items-center gap-3 rounded-xl border px-3.5 py-3 cursor-pointer transition" :class="target === 'advance' ? 'border-orange-500 bg-orange-50/40 ring-1 ring-orange-500' : 'border-gray-200'">
                        <input type="radio" value="advance" x-model="target" class="text-orange-600 focus:ring-orange-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-900">Pay in advance</span>
                            <span class="block text-xs text-gray-500">Kept as credit on your account and applied to future invoices.</span>
                        </span>
                    </label>
                </div>
            </section>

            <section class="bg-white rounded-2xl border border-gray-200 p-4 lg:p-6 lg:shadow-sm space-y-4 lg:grid lg:grid-cols-2 lg:gap-6 lg:space-y-0">
                <div>
                    <x-label :required="true">Amount (UGX)</x-label>
                    <x-input type="number" name="amount" min="{{ \App\Http\Controllers\TenantPortal\PaymentController::MIN_AMOUNT }}" step="1" inputmode="numeric" x-model="amount" required class="text-lg font-semibold" />
                    <p class="mt-1 text-xs text-red-600" x-show="tooMuch" x-cloak>That is more than the <span x-text="fmt(invoice ? invoice.balance : 0)"></span> due on this invoice. Pay the rest in advance instead.</p>
                    <p class="mt-1 text-xs text-gray-500" x-show="invoice && !tooMuch">You can pay all of it or part of it.</p>
                    @error('amount')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-label :required="true">Mobile money number</x-label>
                    <x-input name="phone" type="tel" inputmode="tel" value="{{ old('phone', $defaultPhone) }}" placeholder="0772 123 456" required />
                    <p class="mt-1 text-xs text-gray-500">MTN or Airtel. You'll get a prompt on this phone to approve with your PIN.</p>
                    @error('phone')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            </section>

            </div>

            <aside class="space-y-4 lg:sticky lg:top-24">
            <section class="rounded-2xl bg-gray-100 lg:bg-white lg:border lg:border-gray-200 lg:shadow-sm p-4 lg:p-6 text-sm" x-show="Number(amount) > 0 || window.innerWidth >= 1024">
                <h2 class="hidden lg:block mb-3 text-sm font-semibold text-gray-900">Summary</h2>
                <div class="flex justify-between text-gray-700"><span>Amount</span><span x-text="'UGX ' + fmt(amount || 0)"></span></div>
                <template x-if="passFee">
                    <div class="mt-1 flex justify-between text-gray-700"><span>Mobile money collection fee</span><span x-text="'UGX ' + fmt(fee)"></span></div>
                </template>
                <div class="mt-2 pt-2 border-t border-gray-200 flex justify-between font-semibold text-gray-900 text-base"><span>You'll pay</span><span x-text="'UGX ' + fmt(total)"></span></div>
            </section>

            <x-button type="submit" class="w-full justify-center py-3" x-bind:disabled="busy || tooMuch || !(Number(amount) >= {{ \App\Http\Controllers\TenantPortal\PaymentController::MIN_AMOUNT }})">
                <span x-show="!busy">Send payment request</span>
                <span x-show="busy" x-cloak>Sending…</span>
            </x-button>
            <div class="hidden lg:flex items-start gap-2 rounded-xl bg-gray-50 border border-gray-100 p-3 text-xs text-gray-500">
                <svg class="w-4 h-4 mt-px shrink-0 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                <span>You approve the payment on your own phone with your mobile money PIN. {{ config('app.name') }} never asks for your PIN.</span>
            </div>
            </aside>
        </form>
    @endif
</x-tenant-layout>
