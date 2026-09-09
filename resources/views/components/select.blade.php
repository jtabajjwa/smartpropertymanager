@props(['disabled' => false])

<select {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-900 focus:bg-gray-50 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition disabled:opacity-50 disabled:cursor-not-allowed', 'style' => 'color-scheme: light']) !!}>
    {{ $slot }}
</select>
