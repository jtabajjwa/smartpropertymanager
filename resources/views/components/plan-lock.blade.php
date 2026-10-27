{{-- A small padlock marking a feature the landlord's plan doesn't include. --}}
@props(['dark' => false])
<svg {{ $attributes->merge(['class' => 'inline-block h-3.5 w-3.5 shrink-0 '.($dark ? 'text-gray-500' : 'text-gray-400')]) }} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-label="Not in your plan" role="img">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>
</svg>
