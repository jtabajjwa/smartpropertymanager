{{--
    The Primora lockup as on the website: mark + "Primora" + PROPTECH.
    On dark backgrounds pass :dark="true" — the navy parts of the mark turn white
    (the terracotta chevron stays), like the website header over its banner.
    :wordmark="false" gives the mark alone.
--}}
@props(['dark' => false, 'wordmark' => true, 'size' => 'md'])

@php
    $ink = $dark ? '#ffffff' : '#1c2230';
    [$markClass, $nameClass, $tagClass] = match ($size) {
        'xs' => ['w-4 h-4', 'text-xs', 'hidden'],
        'sm' => ['w-7 h-7', 'text-base', 'text-[7px] mt-0.5'],
        'lg' => ['w-11 h-11', 'text-2xl', 'text-[10px] mt-1'],
        default => ['w-9 h-9', 'text-[21px]', 'text-[9px] mt-1'],
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    <svg class="{{ $markClass }} shrink-0" viewBox="0 0 120 120" fill="none" aria-hidden="true">
        <path d="M15 55 L60 15 L105 55" stroke="{{ $ink }}" stroke-width="11" stroke-linecap="square" stroke-linejoin="miter"/>
        <path d="M35 72 L60 50 L85 72" stroke="#c1704a" stroke-width="10" stroke-linecap="square" stroke-linejoin="miter"/>
        <rect x="54" y="83" width="12" height="17" rx="1" fill="{{ $ink }}"/>
    </svg>
    @if($wordmark)
        <span class="flex flex-col leading-none">
            <span class="{{ $nameClass }} font-bold tracking-tight {{ $dark ? 'text-white' : 'text-navy-800' }}">{{ config('app.name', 'Primora') }}</span>
            <span class="{{ $tagClass }} font-semibold tracking-[0.28em] {{ $dark ? 'text-orange-300' : 'text-orange-500' }}">PROPTECH</span>
        </span>
    @endif
</span>
