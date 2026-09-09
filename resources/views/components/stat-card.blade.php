@props(['label', 'value', 'icon' => null, 'color' => 'orange'])

@php
$iconColor = match($color) {
    'orange' => 'text-orange-600 bg-orange-500/10',
    'green' => 'text-green-600 bg-green-500/10',
    'red' => 'text-red-600 bg-red-500/10',
    'blue' => 'text-blue-600 bg-blue-500/10',
    'gray' => 'text-gray-600 bg-gray-100',
    default => 'text-orange-600 bg-orange-500/10',
};
@endphp

<div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
    <div class="flex items-center justify-between">
        <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">{{ $label }}</p>
        @if($icon)
            <div class="w-8 h-8 rounded-xl {{ $iconColor }} flex items-center justify-center">
                {!! $icon !!}
            </div>
        @endif
    </div>
    <p class="mt-2 text-2xl font-bold text-gray-900">{{ $value }}</p>
    @if(isset($sub))
        <p class="mt-1 text-xs text-gray-500">{{ $sub }}</p>
    @endif
</div>
