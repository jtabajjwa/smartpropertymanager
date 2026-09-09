@props(['iconSize' => 'w-14 h-14', 'wordmarkSize' => 'text-3xl', 'showTagline' => true])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center']) }}>
    <svg class="{{ $iconSize }}" viewBox="0 0 120 120" fill="none">
        <path d="M15 55 L60 15 L105 55" stroke="#1c2230" stroke-width="11" stroke-linecap="square" stroke-linejoin="miter"/>
        <path d="M35 72 L60 50 L85 72" stroke="#c1704a" stroke-width="10" stroke-linecap="square" stroke-linejoin="miter"/>
        <rect x="54" y="83" width="12" height="17" rx="1" fill="#1c2230"/>
    </svg>

    <p class="mt-2 {{ $wordmarkSize }} font-bold tracking-tight text-navy-800">{{ config('app.name', 'Primora') }}</p>

    @if ($showTagline)
        <p class="mt-1 text-xs font-semibold tracking-[0.25em] text-orange-500">PROPTECH</p>
    @endif
</div>
