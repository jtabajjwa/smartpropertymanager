{{-- How one alert went: SMS, or WhatsApp (and, if WhatsApp failed, that it went by SMS instead). --}}
@if($log->channel === 'whatsapp')
    @php
        $wa = [
            'pending' => ['gray', 'WhatsApp · sending'], 'sent' => ['green', 'WhatsApp · sent'],
            'delivered' => ['green', 'WhatsApp · delivered'], 'read' => ['green', 'WhatsApp · read'],
        ][$log->status] ?? ($log->resent_log_id ? ['orange', 'WhatsApp failed → sent by SMS'] : ['red', 'WhatsApp · failed']);
    @endphp
    <x-badge :color="$wa[0]">{{ $wa[1] }}</x-badge>
@elseif($log->status === 'sent')
    <x-badge color="green">Sent</x-badge>
@elseif($log->status === 'no_credit')
    <x-badge color="orange">No credit</x-badge>
@elseif($log->status === 'read_only')
    <x-badge color="gray">Not sent: read-only</x-badge>
@else
    <x-badge color="red">Failed</x-badge>
@endif
