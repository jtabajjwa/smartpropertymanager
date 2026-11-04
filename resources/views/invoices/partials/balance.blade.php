{{-- The balance figure; once payments have been put against the invoice, it opens them. --}}
@if($invoice->allocations->isNotEmpty())
    <button type="button" title="See the payments for this invoice"
        @click="showPayments({ ...{{ Js::from($invoice->makeHidden('allocations')) }}, balance: {{ $balance }}, payments: {{ Js::from($invoice->paymentTrail()) }} })"
        class="underline decoration-dotted underline-offset-4 hover:decoration-solid">UGX {{ number_format($balance, 0) }}</button>
@else
    UGX {{ number_format($balance, 0) }}
@endif
