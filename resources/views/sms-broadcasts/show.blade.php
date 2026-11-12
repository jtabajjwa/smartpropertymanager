<x-app-layout>
    <x-slot name="header">Bulk SMS</x-slot>

    @php
        $done = $broadcast->sent_count + $broadcast->failed_count;
        $pct = $broadcast->recipients_count ? min(100, round($done * 100 / $broadcast->recipients_count)) : 0;
        $smsStatus = ['sent' => ['Sent', 'green'], 'failed' => ['Failed', 'red'], 'no_credit' => ['No credit', 'orange'], 'read_only' => ['Account read-only', 'gray'], 'pending' => ['Sending', 'blue']];
    @endphp

    <div class="max-w-6xl mx-auto space-y-6">
        {{-- While sending, reload every few seconds to show progress. --}}
        @if($broadcast->status !== 'completed')
            <div x-data x-init="setTimeout(() => window.location.reload(), 3000)"></div>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('sms-broadcasts.index') }}" class="text-sm text-gray-500 hover:text-gray-800">← Bulk SMS</a>
                <h1 class="mt-1 text-2xl font-bold text-gray-900">Bulk SMS · {{ $broadcast->created_at->format('d M Y, H:i') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $broadcast->audienceLabel() }} · by {{ $broadcast->createdBy?->name ?? '—' }}</p>
            </div>
            <a href="{{ route('sms-broadcasts.create', ['from' => $broadcast->id]) }}" class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:border-orange-300">Send again / edit</a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <div class="lg:col-span-1 space-y-4">
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Message</p>
                    <p class="mt-2 rounded-2xl rounded-bl-md bg-gray-100 px-3.5 py-2.5 text-sm text-gray-900 whitespace-pre-wrap break-words">{{ $broadcast->message }}</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-900">Progress</p>
                        <x-badge :color="$broadcast->statusColor()">{{ $broadcast->statusLabel() }}</x-badge>
                    </div>
                    <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full {{ $broadcast->failed_count && $broadcast->status === 'completed' ? 'bg-orange-500' : 'bg-green-500' }} transition-all" style="width: {{ $broadcast->status === 'completed' ? 100 : $pct }}%"></div>
                    </div>
                    <dl class="mt-4 space-y-1.5 text-sm">
                        <div class="flex justify-between text-gray-600"><dt>Tenants</dt><dd class="font-medium text-gray-900">{{ number_format($broadcast->recipients_count) }}</dd></div>
                        <div class="flex justify-between text-gray-600"><dt>Sent</dt><dd class="font-medium text-green-700">{{ number_format($broadcast->sent_count) }}</dd></div>
                        <div class="flex justify-between text-gray-600"><dt>Not sent</dt><dd class="font-medium {{ $broadcast->failed_count ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($broadcast->failed_count) }}</dd></div>
                        <div class="flex justify-between text-gray-600"><dt>SMS credits used</dt><dd class="font-medium text-gray-900">{{ number_format($broadcast->credits_used) }}</dd></div>
                    </dl>
                    @if($broadcast->status !== 'completed')
                        <p class="mt-3 text-xs text-gray-500">Sending in the background. You can leave this page; it carries on.</p>
                    @elseif($broadcast->failed_count)
                        <p class="mt-3 text-xs text-gray-500">Credit is returned for any SMS that wasn't sent.</p>
                    @endif
                </div>
            </div>

            <section class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-900">Messages</h2>
                    <div class="inline-flex gap-1 rounded-xl bg-gray-100 p-1 text-xs">
                        <a href="{{ route('sms-broadcasts.show', $broadcast) }}" class="rounded-lg px-3 py-1 font-medium {{ request('status') !== 'failed' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500' }}">All</a>
                        <a href="{{ route('sms-broadcasts.show', [$broadcast, 'status' => 'failed']) }}" class="rounded-lg px-3 py-1 font-medium {{ request('status') === 'failed' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500' }}">Not sent</a>
                    </div>
                </div>
                @forelse($logs as $log)
                    <div class="px-6 py-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ $log->tenant?->name ?? '—' }} <span class="font-normal text-gray-400">· {{ $log->phone }}</span></p>
                                <p class="mt-0.5 text-sm text-gray-600 break-words">{{ $log->message }}</p>
                                @if($log->status !== 'sent' && $log->error)<p class="mt-0.5 text-xs text-red-600">{{ $log->error }}</p>@endif
                            </div>
                            @php [$label, $color] = $smsStatus[$log->status] ?? [ucfirst($log->status), 'gray']; @endphp
                            <x-badge :color="$color" class="shrink-0">{{ $label }}</x-badge>
                        </div>
                    </div>
                @empty
                    <p class="px-6 py-10 text-center text-sm text-gray-500">{{ $broadcast->status === 'completed' ? 'Nothing here.' : 'Messages will appear here as they are sent…' }}</p>
                @endforelse
                @if($logs->hasPages())
                    <div class="px-6 py-4 border-t border-gray-100">{{ $logs->links() }}</div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
