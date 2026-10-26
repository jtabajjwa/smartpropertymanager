{{--
    Map + coordinate fields for an estate's location. Use inside a form; posts `latitude` and `longitude`.
    `lat` / `lng` are Alpine expressions for the starting values (e.g. "editing.latitude"), or null.
--}}
@props(['lat' => 'null', 'lng' => 'null'])

@php
    $inputClass = 'w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition';
    $buttonClass = 'inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 transition disabled:opacity-50 whitespace-nowrap';
@endphp

<div x-data="mapPicker({{ $lat }}, {{ $lng }})" class="space-y-2">
    <x-label>Map location <span class="font-normal text-gray-400">(optional)</span></x-label>

    <div class="flex gap-2">
        <input type="text" x-model="query" @keydown.enter.prevent="search()" placeholder="Search an area, street or landmark…" class="{{ $inputClass }}" autocomplete="off">
        <button type="button" @click="search()" :disabled="searching" class="{{ $buttonClass }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
            <span x-text="searching ? 'Searching…' : 'Search'"></span>
        </button>
    </div>

    <div class="relative z-0">
        <div x-ref="map" class="h-60 rounded-xl border border-gray-200 bg-gray-100 overflow-hidden"></div>
        <div x-show="!ready" class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">Loading map…</div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-[1fr_1fr_auto] gap-2">
        <input type="text" name="latitude" :value="lat" @input="typed('lat', $event.target.value)" inputmode="decimal" placeholder="Latitude" aria-label="Latitude" class="{{ $inputClass }}">
        <input type="text" name="longitude" :value="lng" @input="typed('lng', $event.target.value)" inputmode="decimal" placeholder="Longitude" aria-label="Longitude" class="{{ $inputClass }}">
        <div class="col-span-2 sm:col-span-1 flex gap-2">
            <button type="button" @click="locate()" :disabled="locating" class="{{ $buttonClass }}" title="Use this device's location">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                <span x-text="locating ? 'Locating…' : 'My location'"></span>
            </button>
            <button type="button" x-show="hasPin" @click="clear()" class="px-3 py-2 text-sm text-gray-500 hover:text-red-600 transition">Clear</button>
        </div>
    </div>

    <p class="text-xs" :class="message ? 'text-orange-700' : 'text-gray-500'"
       x-text="message || (hasPin ? 'Drag the pin to fine-tune its position.' : 'Click the map to drop a pin, search above, or paste coordinates (e.g. 0.3476, 32.5825).')"></p>
</div>
