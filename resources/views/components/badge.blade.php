@props(['color' => 'gray'])

@php
$classes = match($color) {
    'green' => 'bg-green-500/10 text-green-400 ring-green-500/20',
    'orange' => 'bg-orange-500/10 text-orange-400 ring-orange-500/20',
    'red' => 'bg-red-500/10 text-red-400 ring-red-500/20',
    'blue' => 'bg-blue-500/10 text-blue-400 ring-blue-500/20',
    'gray' => 'bg-white/10 text-gray-400 ring-white/10',
    default => 'bg-white/10 text-gray-400 ring-white/10',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset $classes"]) }}>
    {{ $slot }}
</span>
