@props(['disabled' => false])

<select {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'w-full rounded-xl border border-white/10 bg-gray-800 px-4 py-2.5 text-sm text-white focus:bg-gray-700 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition disabled:opacity-50 disabled:cursor-not-allowed', 'style' => 'color-scheme: dark']) !!}>
    {{ $slot }}
</select>
