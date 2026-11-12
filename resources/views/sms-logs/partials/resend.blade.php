{{-- Resend a failed SMS (SmsLogController@resend), or show that it was resent and how that went. --}}
@if($log->resent_at)
    <span class="inline-flex items-center gap-1 text-xs text-gray-500" title="Resent {{ $log->resent_at->format('d M Y, H:i') }}">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
        Resent{{ $log->resentAs ? ($log->resentAs->status === 'sent' ? ' · delivered to the network' : ' · failed again') : '' }}
    </span>
@elseif($log->canResend())
    <form method="POST" action="{{ route('sms-logs.resend', $log) }}" class="inline" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <button type="submit" x-bind:disabled="busy" class="inline-flex items-center gap-1 rounded-lg border border-orange-200 bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 hover:bg-orange-100 disabled:opacity-50">
            <svg class="h-3.5 w-3.5" :class="busy && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
            <span x-text="busy ? 'Sending…' : 'Resend'">Resend</span>
        </button>
    </form>
@endif
