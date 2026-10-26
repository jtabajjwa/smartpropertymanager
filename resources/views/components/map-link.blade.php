{{-- Small pin that opens an estate's coordinates in Google Maps; renders nothing if it has none. --}}
@props(['estate'])

@if($estate->hasCoordinates())
    <a href="{{ $estate->mapUrl() }}" target="_blank" rel="noopener" title="Open in Google Maps ({{ $estate->latitude }}, {{ $estate->longitude }})"
       {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-xs font-medium text-orange-600 hover:text-orange-700 transition']) }}>
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
        Map
    </a>
@endif
