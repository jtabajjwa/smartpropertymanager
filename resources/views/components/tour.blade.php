{{--
    A guided tour of this page (App\Support\Tours, resources/js/tour.js):
    offers itself once with a small prompt, and starts straight away when the
    page is opened from "Take a tour" (?tour=1). Landlord users only.

    <x-tour id="dashboard" />
--}}
@props(['id'])

@php
    $user = auth()->user();
    $tour = $user instanceof \App\Models\User ? \App\Support\Tours::get($id, $user) : null;
@endphp

@if ($tour)
    <div x-data="tour(@js([
            'id' => $id,
            'steps' => $tour['steps'],
            'offer' => \App\Support\Tours::shouldOffer($id, $user),
            'autostart' => request()->boolean('tour'),
            'seenUrl' => route('guide.dismiss', \App\Support\Tours::key($id)),
            'offUrl' => route('guide.dismiss', \App\Support\Tours::OFF),
        ]))">
        <div x-show="prompt" x-cloak
            x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-3" x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            role="dialog" aria-label="{{ $tour['title'] }}"
            class="fixed bottom-4 right-4 left-4 sm:left-auto sm:w-80 z-40 bg-white rounded-2xl shadow-xl ring-1 ring-gray-200 p-5">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-orange-500/10 text-orange-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-900">{{ $tour['title'] }}</p>
                    <p class="mt-1 text-sm text-gray-600">{{ $tour['intro'] }}</p>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button type="button" @click="decline()" class="flex-1 rounded-xl bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 transition">No thanks</button>
                <button type="button" @click="start()" class="flex-1 rounded-xl bg-orange-500 px-3 py-2 text-sm font-medium text-white hover:bg-orange-600 transition">Show me</button>
            </div>
            <button type="button" @click="turnOff()" class="mt-3 w-full text-center text-xs text-gray-400 hover:text-gray-600">Don’t offer tours again</button>
        </div>
    </div>
@endif
