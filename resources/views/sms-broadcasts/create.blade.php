<x-app-layout>
    <x-slot name="header">Bulk SMS</x-slot>

    @php
        $templates = [
            'Rent reminder' => "Dear {first_name}, this is a friendly reminder that your rent for {unit} is due. Your balance is {balance}. Thank you. - {landlord}",
            'Maintenance notice' => "Dear {first_name}, please note there will be maintenance work at {estate} on [date] from [time]. We apologise for any inconvenience. - {landlord}",
            'Announcement' => "Dear {first_name}, ",
            'Season greetings' => "Dear {first_name}, everyone at {landlord} wishes you and your family a happy festive season. Thank you for being our tenant at {estate}.",
        ];
        $initial = old('message', $from?->message ?? '');
        $initialEstates = array_map('intval', old('estates', $from?->audience['estates'] ?? []));
        $initialOwing = (bool) old('owing_only', $from?->audience['owing_only'] ?? false);
    @endphp

    <div class="max-w-6xl mx-auto"
         x-data="bulkSms({
             recipients: {{ Js::from($recipients) }},
             landlord: {{ Js::from($landlordName) }},
             balance: {{ $balance }},
             max: {{ \App\Services\SmsBroadcasts::MAX_LENGTH }},
             message: {{ Js::from($initial) }},
             estates: {{ Js::from($initialEstates) }},
             owingOnly: {{ $initialOwing ? 'true' : 'false' }},
         })">
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('sms-broadcasts.index') }}" class="text-sm text-gray-500 hover:text-gray-800">← Bulk SMS</a>
                <h1 class="mt-1 text-2xl font-bold text-gray-900">New bulk SMS</h1>
                <p class="mt-1 text-sm text-gray-500">Write one message; each tenant gets it with their own name, unit and balance filled in.</p>
            </div>
            <div class="rounded-xl bg-white border border-gray-200 px-4 py-2 text-sm">
                <span class="text-gray-500">SMS credit</span>
                <span class="ml-1 font-semibold {{ $balance > 0 ? 'text-gray-900' : 'text-red-600' }}">{{ number_format($balance) }}</span>
                <a href="{{ route('sms-credits.show') }}" class="ml-2 font-medium text-orange-600 hover:text-orange-700">Buy SMS</a>
            </div>
        </div>

        <form method="POST" action="{{ route('sms-broadcasts.store') }}" x-ref="form" class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start"
              @submit.prevent="if (canSend) $store.confirm.open('Send this SMS to ' + fmt(list.length) + ' ' + (list.length === 1 ? 'tenant' : 'tenants') + '? It will use about ' + fmt(credits) + ' SMS credits.', $el)">
            @csrf

            <div class="lg:col-span-3 space-y-6">
                @error('audience')
                    <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>{{ $message }}</span>
                    </div>
                @enderror

                {{-- 1. Who --}}
                <section class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <h2 class="text-base font-semibold text-gray-900"><span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-orange-100 text-xs font-bold text-orange-700">1</span>Who gets it</h2>
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" class="sr-only" value="all" x-model="scope">
                            <span class="block rounded-xl border px-4 py-3 text-sm transition" :class="scope === 'all' ? 'border-orange-500 bg-orange-50' : 'border-gray-200 hover:border-gray-300'">
                                <span class="block font-medium text-gray-900">All current tenants</span>
                                <span class="block text-xs text-gray-500">Everyone with an active lease</span>
                            </span>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" class="sr-only" value="estates" x-model="scope">
                            <span class="block rounded-xl border px-4 py-3 text-sm transition" :class="scope === 'estates' ? 'border-orange-500 bg-orange-50' : 'border-gray-200 hover:border-gray-300'">
                                <span class="block font-medium text-gray-900">Tenants in some estates</span>
                                <span class="block text-xs text-gray-500">Choose the estates</span>
                            </span>
                        </label>
                    </div>
                    <div x-show="scope === 'estates'" x-cloak class="mt-3 flex flex-wrap gap-2">
                        @forelse($estates as $estate)
                            <label class="cursor-pointer">
                                <input type="checkbox" class="sr-only" value="{{ $estate->id }}" x-model.number="estates" :name="scope === 'estates' ? 'estates[]' : ''">
                                <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition" :class="estates.includes({{ $estate->id }}) ? 'border-orange-500 bg-orange-50 text-orange-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'">
                                    <svg x-show="estates.includes({{ $estate->id }})" class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    {{ $estate->name }}
                                </span>
                            </label>
                        @empty
                            <p class="text-sm text-gray-500">You have no estates yet.</p>
                        @endforelse
                    </div>
                    <label class="mt-4 flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" name="owing_only" value="1" x-model="owingOnly" class="mt-0.5 rounded border-gray-300 text-orange-600 focus:ring-orange-500">
                        <span class="text-sm"><span class="font-medium text-gray-900">Only tenants who owe rent</span><span class="block text-xs text-gray-500">Tenants with an unpaid balance (after any money paid in advance).</span></span>
                    </label>
                    @if($withoutPhone > 0)
                        <p class="mt-3 text-xs text-gray-500">{{ $withoutPhone }} current {{ \Illuminate\Support\Str::plural('tenant', $withoutPhone) }} {{ $withoutPhone === 1 ? 'has' : 'have' }} no valid phone number and won't get it.</p>
                    @endif
                </section>

                {{-- 2. Message --}}
                <section class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-900"><span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-orange-100 text-xs font-bold text-orange-700">2</span>Your message</h2>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($templates as $label => $text)
                                <button type="button" class="rounded-lg bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200" @click="useTemplate({{ Js::from($text) }})">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <x-textarea name="message" x-ref="message" x-model="message" rows="6" class="mt-4" maxlength="{{ \App\Services\SmsBroadcasts::MAX_LENGTH }}" placeholder="Dear {first_name}, …" required></x-textarea>
                    @error('message')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                        <span>Insert:</span>
                        <div class="flex flex-1 flex-wrap gap-1.5">
                            @foreach(\App\Services\SmsBroadcasts::PLACEHOLDERS as $tag => $meaning)
                                <button type="button" title="{{ $meaning }}" class="rounded-md border border-orange-200 bg-orange-50 px-2 py-0.5 font-mono text-[11px] text-orange-700 hover:bg-orange-100" @click="insert('{{ $tag }}')">{{ $tag }}</button>
                            @endforeach
                        </div>
                        <span x-text="message.length + ' / ' + max"></span>
                    </div>
                </section>
            </div>

            {{-- Preview & cost --}}
            <aside class="lg:col-span-2 space-y-4 lg:sticky lg:top-20">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-900">Preview</p>
                        <div class="flex items-center gap-1 text-xs text-gray-500" x-show="list.length > 1">
                            <button type="button" class="rounded-md p-1 hover:bg-gray-100" @click="at = (at - 1 + list.length) % list.length">‹</button>
                            <span x-text="(at + 1) + ' of ' + list.length"></span>
                            <button type="button" class="rounded-md p-1 hover:bg-gray-100" @click="at = (at + 1) % list.length">›</button>
                        </div>
                    </div>
                    <template x-if="sample">
                        <div class="mt-3 rounded-2xl bg-gray-100 p-4">
                            <p class="text-xs text-gray-500"><span class="font-medium text-gray-700" x-text="sample.name"></span> · <span x-text="sample.unit || '—'"></span></p>
                            <div class="mt-2 max-w-[90%] rounded-2xl rounded-bl-md bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-sm whitespace-pre-wrap break-words" x-text="render(sample) || 'Your message will appear here.'" :class="!message.trim() && 'text-gray-400'"></div>
                            <p class="mt-2 text-[11px] text-gray-500" x-text="render(sample).length + ' characters · ' + parts(render(sample)) + ' SMS'"></p>
                            <p class="mt-1 text-[11px] text-orange-700" x-show="full(sample).length > max">Too long for this tenant: it will be cut at {{ \App\Services\SmsBroadcasts::MAX_LENGTH }} characters.</p>
                        </div>
                    </template>
                    <p x-show="!sample" class="mt-3 rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">No tenants match.</p>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 text-sm">
                    <div class="flex justify-between text-gray-600"><span>Tenants</span><span class="font-medium text-gray-900" x-text="fmt(list.length)"></span></div>
                    <div class="mt-1.5 flex justify-between text-gray-600"><span>SMS credits needed</span><span class="font-medium text-gray-900" x-text="fmt(credits)"></span></div>
                    <div class="mt-1.5 flex justify-between text-gray-600"><span>Your SMS credit</span><span class="font-medium" :class="credits > balance ? 'text-red-600' : 'text-gray-900'" x-text="fmt(balance)"></span></div>
                    <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700" x-show="credits > balance" x-cloak>
                        Not enough SMS credit. <a href="{{ route('sms-credits.show') }}" class="font-semibold underline">Buy SMS</a>, or send to fewer tenants.
                    </p>
                    <p class="mt-3 text-xs text-gray-500">Each 160 characters is 1 SMS credit per tenant (70 if the message has emoji or special characters).</p>
                    <x-button type="submit" class="mt-4 w-full justify-center" x-bind:disabled="!canSend">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                        <span x-text="list.length ? 'Send to ' + fmt(list.length) + ' ' + (list.length === 1 ? 'tenant' : 'tenants') : 'Send'"></span>
                    </x-button>
                </div>

                <details class="bg-white rounded-2xl border border-gray-200 shadow-sm" x-show="list.length">
                    <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-gray-700">See who gets it (<span x-text="list.length"></span>)</summary>
                    <ul class="max-h-72 overflow-y-auto border-t border-gray-100 divide-y divide-gray-100">
                        <template x-for="(r, i) in list" :key="r.phone">
                            <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm cursor-pointer hover:bg-gray-50" @click="at = i">
                                <span class="min-w-0"><span class="block truncate text-gray-900" x-text="r.name"></span><span class="block truncate text-xs text-gray-500" x-text="[r.unit, r.estate].filter(Boolean).join(' · ')"></span></span>
                                <span class="shrink-0 text-xs" :class="r.balance > 0 ? 'text-red-600' : 'text-gray-400'" x-text="r.balance > 0 ? 'Owes UGX ' + fmt(r.balance) : 'Paid up'"></span>
                            </li>
                        </template>
                    </ul>
                </details>
            </aside>
        </form>
    </div>

</x-app-layout>
