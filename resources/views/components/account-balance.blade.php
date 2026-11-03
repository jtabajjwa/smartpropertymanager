{{-- What a tenant owes at a glance (figures from App\Services\AccountBalances). --}}
@props(['balance' => null])

@php $b = $balance ?? \App\Services\AccountBalances::EMPTY; @endphp

<div {{ $attributes->merge(['class' => 'text-sm tabular-nums']) }}>
    @if($b['owed'] > 0)
        <span class="font-medium text-orange-600">UGX {{ number_format($b['owed']) }}</span>
        @if($b['overdue'] > 0)
            <span class="block text-xs font-medium text-red-600">UGX {{ number_format($b['overdue']) }} overdue</span>
        @endif
    @else
        <span class="font-medium text-green-600">Paid up</span>
    @endif
    @if($b['credit'] > 0)
        <span class="block text-xs text-green-700" title="Paid but not yet put against an invoice">UGX {{ number_format($b['credit']) }} credit</span>
    @endif
</div>
