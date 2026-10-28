{{--
    How a completed job's cost goes into the books (App\Services\JobCosting).
    Expects an Alpine object `f` in scope: status, record, wasCompleted, expense
    (the linked expense or null), expense_item_id, expense_item_name, paid_on.
--}}
<div x-show="f.status === 'completed'" x-cloak class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 space-y-4">
    <div>
        <p class="text-sm font-semibold text-gray-900">Record the cost</p>
        <p class="mt-0.5 text-xs text-gray-500">A completed job goes into your books as a paid expense.</p>
    </div>

    <div class="space-y-2">
        @foreach(\App\Services\JobCosting::RECORD_OPTIONS as $value => $label)
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="radio" name="record" value="{{ $value }}" x-model="f.record" :disabled="f.status !== 'completed'" class="accent-orange-600">
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>

    <template x-if="f.record === 'expense'">
        <div class="space-y-4">
            <p x-show="f.expense" class="text-xs text-gray-600">Expense <span class="font-medium text-gray-900" x-text="f.expense?.number"></span> will be updated to match this job.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-label :required="true">Expense item</x-label>
                    <x-search-select name="expense_item_id" url="{{ route('search.expense-items') }}" placeholder="Search expense items..." x-init="setValue(f.expense_item_id, f.expense_item_name)" />
                </div>
                <div>
                    <x-label :required="true">Date paid</x-label>
                    <x-input type="date" name="paid_on" x-model="f.paid_on" max="{{ today()->toDateString() }}" />
                </div>
            </div>
            <p class="text-xs text-gray-500">Uses the Cost above, and the job's estate and unit.</p>
        </div>
    </template>

    <template x-if="f.record === 'existing'">
        <div>
            <x-label :required="true">Expense</x-label>
            <x-search-select name="expense_id" url="{{ route('search.expenses', ['unlinked' => 1]) }}" placeholder="Search expenses not linked to a job..." x-init="setValue(f.expense?.id, f.expense ? (f.expense.number + ' — UGX ' + Number(f.expense.amount).toLocaleString()) : '')" />
        </div>
    </template>

    <p x-show="f.record === 'none'" class="text-xs text-gray-500">Nothing is recorded in your books for this job.</p>
</div>

{{-- Reopening or cancelling a completed job that has an expense. --}}
<label x-show="f.wasCompleted && f.status !== 'completed' && f.expense" x-cloak class="flex items-start gap-2 rounded-xl border border-orange-200 bg-orange-50 p-3 text-sm text-gray-700">
    <input type="checkbox" name="remove_expense" value="1" class="mt-0.5 rounded accent-orange-600">
    <span>Also delete expense <span class="font-medium" x-text="f.expense?.number"></span>, recorded when this job was completed.</span>
</label>
