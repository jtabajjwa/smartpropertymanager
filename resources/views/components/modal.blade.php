@props(['title' => 'Modal', 'maxWidth' => 'lg'])

@php
$maxWidthClass = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth] ?? 'sm:max-w-lg';
@endphp

<div x-show="show" class="relative z-50" style="display: none;" x-on:keydown.escape.window="show = false">
    <!-- Backdrop -->
    <div x-show="show" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm"></div>

    <!-- Panel -->
    <div class="fixed inset-0 z-10 overflow-y-auto" @click="show = false">
        <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-0">
            <div x-show="show" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" @click.stop class="relative w-full {{ $maxWidthClass }} bg-white rounded-2xl shadow-2xl ring-1 ring-gray-200 sm:my-8 overflow-hidden">

                <!-- Header -->
                <div class="px-6 pt-6 pb-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">{{ $title }}</h3>
                        <button type="button" @click="show = false" class="p-1.5 rounded-xl text-gray-500 hover:text-gray-900 hover:bg-gray-100 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Content -->
                <div class="px-6 py-5 max-h-[70vh] overflow-y-auto">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</div>
