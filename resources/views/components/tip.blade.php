{{-- A short, closable tip at the top of a page. Closing it is remembered per user (GuideController). --}}
@props(['id', 'title' => null])

@php($tipUser = auth()->user())
@if ($tipUser instanceof \App\Models\User && ! $tipUser->hasDismissedTip('tip-'.$id))
    {{-- Same width as the page content below it. --}}
    <div x-data="{ open: true }" x-show="open" x-transition.opacity.duration.200ms class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-2xl border border-orange-200/70 bg-white px-4 py-3.5 shadow-sm']) }}>
        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600" aria-hidden="true">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"/></svg>
        </span>
        <div class="min-w-0 flex-1 text-sm leading-relaxed text-gray-600">
            @if ($title)
                <p class="font-semibold text-gray-900">{{ $title }}</p>
            @endif
            {{ $slot }}
        </div>
        <button type="button" title="Got it, hide this tip" aria-label="Hide this tip"
                @click="open = false; fetch('{{ route('guide.dismiss', 'tip-'.$id) }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } })"
                class="-mr-1 shrink-0 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    </div>
@endif
