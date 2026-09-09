@props(['label', 'value', 'icon' => null, 'color' => 'orange'])

@php
$iconColor = match($color) {
    'orange' => 'text-orange-400 bg-orange-500/10',
    'green' => 'text-green-400 bg-green-500/10',
    'red' => 'text-red-400 bg-red-500/10',
    'blue' => 'text-blue-400 bg-blue-500/10',
    'gray' => 'text-gray-400 bg-white/10',
    default => 'text-orange-400 bg-orange-500/10',
};
@endphp

<div class="bg-gray-900 rounded-2xl shadow-sm border border-white/10 p-5">
    <div class="flex items-center justify-between">
        <p class="text-xs font-medium text-gray-400 uppercase tracking-wide">{{ $label }}</p>
        @if($icon)
            <div class="w-8 h-8 rounded-xl {{ $iconColor }} flex items-center justify-center">
                {!! $icon !!}
            </div>
        @endif
    </div>
    <p class="mt-2 text-2xl font-bold text-white">{{ $value }}</p>
    @if(isset($sub))
        <p class="mt-1 text-xs text-gray-500">{{ $sub }}</p>
    @endif
</div>
