@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white'])

@php
$originClass = match ($align) {
    'left' => 'origin-top-left',
    'top' => 'origin-top',
    default => 'origin-top-right',
};

$widthClasses = match ($width) {
    '64' => 'w-64',
    'auto' => 'w-auto min-w-[max-content]',
    default => 'w-48',
};
@endphp

{{--
    The panel is positioned with `position: fixed`, computed in JS from the trigger's
    actual on-screen position — not `absolute` inside this wrapper. A plain `absolute`
    panel gets clipped by any scrollable ancestor (e.g. a table's `overflow-x-auto`
    wrapper), which forces the ancestor itself to grow scrollable just to reveal the
    menu. `fixed` positioning escapes that entirely, regardless of table size.
--}}
<div class="relative" {{ $attributes->only('data-tour') }} x-data="{
    open: false,
    reposition() {
        const trigger = this.$refs.trigger;
        const panel = this.$refs.panel;
        if (!trigger || !panel) return;

        const rect = trigger.getBoundingClientRect();
        const panelRect = panel.getBoundingClientRect();

        let left;
        if ('{{ $align }}' === 'left') {
            left = rect.left;
        } else if ('{{ $align }}' === 'top') {
            left = rect.left + (rect.width / 2) - (panelRect.width / 2);
        } else {
            left = rect.right - panelRect.width;
        }
        left = Math.max(8, Math.min(left, window.innerWidth - panelRect.width - 8));

        let top = rect.bottom + 8;
        if (top + panelRect.height > window.innerHeight - 8) {
            top = rect.top - panelRect.height - 8;
        }
        top = Math.max(8, top);

        panel.style.position = 'fixed';
        panel.style.top = top + 'px';
        panel.style.left = left + 'px';
    }
}" x-effect="if (open) $nextTick(() => reposition())" @click.outside="open = false" @close.stop="open = false" @scroll.window="open = false" @resize.window="open = false">
    <div x-ref="trigger" @click="open = ! open" class="cursor-pointer inline-flex items-center justify-center p-1.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-full transition duration-150 ease-in-out">
        {{ $trigger ?? '' }}
        @if(!isset($trigger))
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/>
            </svg>
        @endif
    </div>

    <div x-ref="panel"
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="transform opacity-0 scale-95"
            x-transition:enter-end="transform opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="transform opacity-100 scale-100"
            x-transition:leave-end="transform opacity-0 scale-95"
            class="z-50 {{ $widthClasses }} rounded-xl shadow-lg border border-gray-300 bg-white {{ $originClass }}"
            style="display: none;">
        <div class="rounded-xl ring-1 ring-black ring-opacity-5 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
