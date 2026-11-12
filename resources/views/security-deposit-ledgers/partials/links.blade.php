{{-- Receipt / photo and linked expense of a deposit ledger entry. --}}
@if($entry->attachment || $entry->expense)
    <span class="mt-0.5 flex flex-wrap gap-x-3 text-xs">
        @if($entry->attachment)
            <a href="{{ Storage::disk('public')->url($entry->attachment) }}" target="_blank" hx-boost="false" class="font-medium text-orange-600 hover:text-orange-700">Receipt / photo</a>
        @endif
        @if($entry->expense)
            <a href="{{ route('expenses.index', ['search' => $entry->expense->number]) }}" class="text-gray-500 hover:text-gray-800">Expense {{ $entry->expense->number }}</a>
        @endif
    </span>
@endif
