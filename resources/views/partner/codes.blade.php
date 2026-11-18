<x-partner-layout title="Codes" subtitle="Share a code or link; landlords who use it are linked to you">
    <div class="space-y-4">
        <div class="rounded-2xl bg-navy-800 text-white p-5 flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-wider text-gray-300">Your agent number</p>
                <p class="text-3xl font-bold tracking-wide font-mono">{{ $partner->number }}</p>
            </div>
            <p class="max-w-md text-sm text-gray-300">Landlords can type your agent number instead of a code. It works like your main code{{ $partner->defaultPromotion ? ', with its offer' : '' }}.</p>
        </div>

        @foreach($codes as $c)
            @php
                $promotion = $c->effectivePromotion();
                $message = 'Manage your rentals with '.config('app.name').': invoices, mobile money rent collection and SMS reminders. '
                    .($promotion ? 'Use my code '.$c->code.' for '.$promotion->summary().'. ' : 'Use my code '.$c->code.' when you sign up. ')
                    .$c->signupUrl();
            @endphp
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 {{ $c->is_active ? '' : 'opacity-60' }}" x-data="{ copied: null, copy(text, what) { navigator.clipboard?.writeText(text); this.copied = what; setTimeout(() => this.copied = null, 1500) } }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-2xl font-bold tracking-wide text-gray-900 font-mono">{{ $c->code }}</p>
                        <p class="text-sm text-gray-500">{{ $c->label ?: ($c->is_default ? 'Your main code' : 'Campaign code') }}{{ $c->is_active ? '' : ' · switched off' }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-bold text-gray-900">{{ $c->landlords_count }}</p>
                        <p class="text-xs text-gray-500">{{ \Illuminate\Support\Str::plural('landlord', $c->landlords_count) }}</p>
                    </div>
                </div>
                <p class="mt-3 text-sm {{ $promotion ? 'text-green-700' : 'text-gray-500' }}">{{ $promotion ? 'Offer: '.$promotion->summary().'.' : 'No discount on this code.' }}</p>
                @if($c->is_active)
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" @click="copy(@js($c->signupUrl()), 'link')" class="rounded-xl border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-orange-300"><span x-text="copied === 'link' ? 'Copied ✓' : 'Copy sign-up link'"></span></button>
                        <button type="button" @click="copy(@js($message), 'message')" class="rounded-xl border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-orange-300"><span x-text="copied === 'message' ? 'Copied ✓' : 'Copy message'"></span></button>
                        <a href="https://wa.me/?text={{ rawurlencode($message) }}" target="_blank" rel="noopener" hx-boost="false" class="rounded-xl border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-orange-300">Share on WhatsApp</a>
                        <a href="sms:?body={{ rawurlencode($message) }}" hx-boost="false" class="rounded-xl border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-orange-300">Send by SMS</a>
                    </div>
                    <p class="mt-3 break-all rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">{{ $message }}</p>
                @endif
            </section>
        @endforeach

        <p class="text-xs text-gray-500">Need a code for a campaign or event? Ask Primora; it will show here.</p>
    </div>
</x-partner-layout>
