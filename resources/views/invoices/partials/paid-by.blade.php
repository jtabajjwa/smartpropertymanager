{{-- "Paid by PAY-00002, PAY-00003": the payments put against this invoice, each opening that payment. --}}
@php $numbers = $invoice->allocations->map(fn ($a) => $a->payment?->number)->filter()->unique()->values(); @endphp
@if($numbers->isNotEmpty())
    <span class="block text-xs font-normal text-gray-500">
        Paid by
        @foreach($numbers->take(2) as $number)
            <a href="{{ route('payments.index', ['search' => $number]) }}" class="text-blue-600 hover:underline">{{ $number }}</a>{{ !$loop->last ? ',' : '' }}
        @endforeach
        @if($numbers->count() > 2)
            +{{ $numbers->count() - 2 }} more
        @endif
    </span>
@endif
