{{-- Small square photo for list rows; opens the full photo in a new tab. Falls back to an icon. --}}
@props(['url' => null, 'size' => 'w-11 h-11', 'icon' => 'building'])

@if($url)
    <a href="{{ $url }}" target="_blank" rel="noopener" title="Open photo" {{ $attributes->merge(['class' => "$size shrink-0 rounded-lg overflow-hidden border border-gray-200 bg-gray-50 block hover:ring-2 hover:ring-orange-500/40 transition"]) }}>
        <img src="{{ $url }}" alt="" loading="lazy" class="w-full h-full object-cover">
    </a>
@else
    <span {{ $attributes->merge(['class' => "$size shrink-0 rounded-lg border border-dashed border-gray-200 bg-gray-50 flex items-center justify-center text-gray-300"]) }} title="No photo yet">
        @if($icon === 'cube')
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>
        @else
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21"/></svg>
        @endif
    </span>
@endif
