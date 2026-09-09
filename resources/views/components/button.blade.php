@props(['variant' => 'primary'])

@php
$classes = match($variant) {
    'primary' => 'bg-orange-500 text-white hover:bg-orange-600 focus:ring-orange-500',
    'secondary' => 'bg-gray-100 text-gray-700 hover:bg-gray-100 hover:text-gray-900 focus:ring-gray-300',
    'danger' => 'bg-red-500 text-white hover:bg-red-600 focus:ring-red-500',
    'ghost' => 'bg-transparent text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus:ring-gray-300',
    default => 'bg-orange-500 text-white hover:bg-orange-600 focus:ring-orange-500',
};
@endphp

<button {{ $attributes->merge(['type' => 'submit', 'class' => "inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-white transition ease-in-out duration-150 $classes"]) }}>
    {{ $slot }}
</button>
