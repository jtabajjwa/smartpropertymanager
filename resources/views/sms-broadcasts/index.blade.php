<x-app-layout>
    <x-slot name="header">Bulk SMS</x-slot>

    <div class="max-w-6xl mx-auto space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Bulk SMS</h1>
                <p class="mt-1 text-sm text-gray-500">Send one message to all your tenants, or to tenants in some estates, with their name, unit and balance filled in.</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-500">SMS credit <span class="font-semibold {{ $balance > 0 ? 'text-gray-900' : 'text-red-600' }}">{{ number_format($balance) }}</span></span>
                <a href="{{ route('sms-broadcasts.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    New bulk SMS
                </a>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            @forelse($broadcasts as $b)
                <a href="{{ route('sms-broadcasts.show', $b) }}" class="block px-6 py-4 hover:bg-gray-50 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-900 line-clamp-2">{{ $b->message }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $b->created_at->format('d M Y, H:i') }} · {{ $b->createdBy?->name ?? '—' }} · {{ $b->audienceLabel() }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3 text-xs text-gray-500">
                            <span><span class="font-semibold text-gray-900">{{ number_format($b->sent_count) }}</span> / {{ number_format($b->recipients_count) }} sent</span>
                            @if($b->failed_count)<span class="text-red-600">{{ number_format($b->failed_count) }} not sent</span>@endif
                            <x-badge :color="$b->statusColor()">{{ $b->statusLabel() }}</x-badge>
                        </div>
                    </div>
                </a>
            @empty
                <div class="px-6 py-14 text-center">
                    <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535"/></svg>
                    <p class="mt-3 text-sm font-medium text-gray-900">No bulk SMS yet</p>
                    <p class="mt-1 text-sm text-gray-500">Remind tenants about rent, announce maintenance, or send greetings to everyone at once.</p>
                    <a href="{{ route('sms-broadcasts.create') }}" class="mt-4 inline-block text-sm font-semibold text-orange-600 hover:text-orange-700">Write your first message →</a>
                </div>
            @endforelse
        </div>
        @if($broadcasts->hasPages())
            <div>{{ $broadcasts->links() }}</div>
        @endif
    </div>
</x-app-layout>
