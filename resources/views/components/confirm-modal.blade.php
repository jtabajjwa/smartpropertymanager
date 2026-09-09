<div x-data x-show="$store.confirm.show" class="relative z-[60]" style="display: none;" x-on:keydown.escape.window="$store.confirm.close()">
    <!-- Backdrop -->
    <div x-show="$store.confirm.show" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-gray-900/40 backdrop-blur-sm"></div>

    <!-- Panel -->
    <div class="fixed inset-0 z-10 overflow-y-auto">
        <div class="flex min-h-full items-end justify-center p-4 sm:items-center sm:p-0">
            <div x-show="$store.confirm.show" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" @click.away="$store.confirm.close()" class="relative w-full sm:max-w-sm bg-white rounded-2xl shadow-2xl ring-1 ring-gray-200 sm:my-8 overflow-hidden">

                <div class="px-6 py-6 text-center">
                    <!-- Warning Icon -->
                    <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-500/10">
                        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                        </svg>
                    </div>

                    <!-- Message -->
                    <p class="text-sm text-gray-700" x-text="$store.confirm.message"></p>
                </div>

                <!-- Actions -->
                <div class="flex border-t border-gray-200">
                    <button type="button" @click="$store.confirm.close()" class="flex-1 px-4 py-3 text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-50 transition">Cancel</button>
                    <button type="button" @click="$store.confirm.proceed()" class="flex-1 px-4 py-3 text-sm font-medium text-red-600 hover:text-red-700 hover:bg-red-500/10 border-l border-gray-200 transition">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>
