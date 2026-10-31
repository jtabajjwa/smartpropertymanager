{{-- The dashboard's "Getting started" checklist for a new landlord (see App\Services\GettingStarted). --}}
@props(['guide'])

@php($pct = $guide['total'] ? round($guide['done'] / $guide['total'] * 100) : 100)

<div {{ $attributes->only('data-tour') }} x-data="{ open: true }" x-show="open" x-transition.opacity.duration.200ms class="mb-6 bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="flex flex-wrap items-start gap-4 px-5 pt-5 pb-4">
        <div class="min-w-0 flex-1">
            @if ($guide['complete'])
                <h2 class="text-base font-semibold text-gray-900">You’re all set up</h2>
                <p class="mt-1 text-sm text-gray-600">Primora now raises rent invoices on schedule and reminds your tenants by SMS. Keep recording payments as they come in, and the dashboard below fills up as you go.</p>
            @else
                <h2 class="text-base font-semibold text-gray-900">Getting started</h2>
                <p class="mt-1 text-sm text-gray-600">A few steps to get your first property running. Each one ticks itself off once it’s done.</p>
            @endif
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm font-medium text-gray-700 whitespace-nowrap">{{ $guide['done'] }} of {{ $guide['total'] }} done</span>
            <form method="POST" action="{{ route('guide.dismiss', \App\Services\GettingStarted::KEY) }}" @submit.prevent="open = false; fetch($el.action, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } })">
                @csrf
                <button type="submit" class="text-sm font-medium text-gray-500 hover:text-gray-900 whitespace-nowrap" title="You can bring it back from the menu under your name">
                    {{ $guide['complete'] ? 'Close' : 'Hide guide' }}
                </button>
            </form>
        </div>
    </div>

    <div class="mx-5 h-1.5 rounded-full bg-gray-100 overflow-hidden" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="Setup progress">
        <div class="h-full rounded-full bg-green-600 transition-all" style="width: {{ $pct }}%"></div>
    </div>

    @unless ($guide['complete'])
        <ol class="mt-4 divide-y divide-gray-100 border-t border-gray-100">
            @foreach ($guide['steps'] as $i => $step)
                @php($isNext = $step['key'] === $guide['next'])
                <li class="flex items-start gap-4 px-5 py-4 {{ $isNext ? 'bg-orange-50/60' : '' }}">
                    @if ($step['done'])
                        <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-green-600 text-white" aria-label="Done">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        </span>
                    @else
                        <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 text-xs font-semibold {{ $isNext ? 'border-orange-500 text-orange-600' : 'border-gray-300 text-gray-500' }}">{{ $i + 1 }}</span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold {{ $step['done'] ? 'text-gray-500 line-through decoration-gray-300' : 'text-gray-900' }}">{{ $step['title'] }}</p>
                        @unless ($step['done'])
                            <p class="mt-0.5 text-sm text-gray-600">{{ $step['text'] }}</p>
                        @endunless
                    </div>
                    @unless ($step['done'])
                        <a href="{{ $step['url'] }}" class="shrink-0 inline-flex items-center gap-1 rounded-xl px-3 py-1.5 text-sm font-medium transition {{ $isNext ? 'bg-orange-500 text-white hover:bg-orange-600' : 'border border-gray-200 text-gray-700 hover:border-orange-500 hover:text-orange-600' }}">
                            {{ $step['action'] }}
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                        </a>
                    @endunless
                </li>
            @endforeach
        </ol>
    @else
        <div class="h-5"></div>
    @endunless
</div>
