<x-staff-layout>
    <x-slot name="header">Unmatched gateway callbacks</x-slot>

    <div class="max-w-6xl mx-auto">
        <a href="{{ route('staff.payments.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            All payments
        </a>

        <div class="mt-3 mb-6">
            <h3 class="text-lg font-semibold text-gray-900">Unmatched gateway callbacks</h3>
            <p class="mt-1 text-sm text-gray-500">Notifications the gateway sent us that don't match any payment started in Primora: for example money sent straight to the pool account, or a callback with a missing or unknown reference. Nothing was credited for these.</p>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 divide-y divide-gray-100">
            @forelse($logs as $log)
                <div class="p-5" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="w-full flex items-start justify-between gap-3 text-left">
                        <span>
                            <span class="block text-sm font-medium text-gray-900">{{ strtoupper($log->gateway) }} · {{ $log->action === 'ipn' ? 'Payment notification (IPN)' : ($log->action === 'failure' ? 'Failure notification' : $log->action) }}</span>
                            <span class="block text-xs text-gray-500">{{ $log->created_at->format('d M Y, H:i:s') }} · {{ $log->note }}</span>
                        </span>
                        <svg class="w-4 h-4 mt-1 text-gray-400 transition" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <pre x-show="open" x-cloak class="mt-3 max-h-80 overflow-auto rounded-lg bg-gray-900 text-gray-100 text-xs p-3 whitespace-pre-wrap break-all">{{ $log->prettyRequest() ?: '(empty)' }}</pre>
                </div>
            @empty
                <p class="px-5 py-12 text-center text-sm text-gray-500">No unmatched callbacks.</p>
            @endforelse
        </div>

        @if($logs->hasPages())
            <div class="mt-4">{{ $logs->links() }}</div>
        @endif
    </div>
</x-staff-layout>
