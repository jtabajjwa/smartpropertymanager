@php
    $formAccounts = \App\Models\LedgerAccount::where('is_active', true)->orderBy('code')->get();
    $money = $formAccounts->where('type', 'asset');
    $expenses = $formAccounts->where('type', 'expense');
    $journalAccounts = $formAccounts->where('system_key', '!=', 'landlord_payable');
@endphp

<div x-data="{ form: null }" @books-form.window="form = $event.detail">
    {{-- Expense --}}
    <template x-if="form === 'expense'">
        <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) form = null })">
            <x-modal title="Record an expense">
                <form method="POST" action="{{ route('staff.books.expenses.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-label :required="true">Date</x-label>
                            <x-input type="date" name="entry_date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required />
                        </div>
                        <div>
                            <x-label :required="true">Amount (UGX)</x-label>
                            <x-input type="number" name="amount" min="1" step="0.01" required />
                        </div>
                    </div>
                    <div>
                        <x-label :required="true">Expense</x-label>
                        <x-select name="expense_account_id" required>
                            @foreach($expenses as $a)<option value="{{ $a->id }}" @selected($a->system_key === 'other_expenses')>{{ $a->code }} · {{ $a->name }}</option>@endforeach
                        </x-select>
                        <p class="mt-1 text-xs text-gray-500">Need another category (rent, salaries…)? Add it in Chart of accounts.</p>
                    </div>
                    <div>
                        <x-label :required="true">Paid from</x-label>
                        <x-select name="paid_from_id" required>
                            @foreach($money as $a)<option value="{{ $a->id }}" @selected($a->system_key === 'bank')>{{ $a->code }} · {{ $a->name }}</option>@endforeach
                        </x-select>
                    </div>
                    <div>
                        <x-label :required="true">Description</x-label>
                        <x-input name="description" maxlength="300" placeholder="e.g. October office rent" required />
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                        <x-button type="submit">Record expense</x-button>
                    </div>
                </form>
            </x-modal>
        </div>
    </template>

    {{-- Transfer --}}
    <template x-if="form === 'transfer'">
        <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) form = null })">
            <x-modal title="Transfer between accounts">
                <form method="POST" action="{{ route('staff.books.transfers.store') }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-gray-600">Moving Primora's money between its own accounts, e.g. withdrawing from the Yo! pool to the bank.</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-label :required="true">From</x-label>
                            <x-select name="from_id" required>
                                @foreach($money as $a)<option value="{{ $a->id }}" @selected($a->system_key === 'yo_pool')>{{ $a->name }}</option>@endforeach
                            </x-select>
                        </div>
                        <div>
                            <x-label :required="true">To</x-label>
                            <x-select name="to_id" required>
                                @foreach($money as $a)<option value="{{ $a->id }}" @selected($a->system_key === 'bank')>{{ $a->name }}</option>@endforeach
                            </x-select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <x-label :required="true">Date</x-label>
                            <x-input type="date" name="entry_date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required />
                        </div>
                        <div>
                            <x-label :required="true">Amount (UGX)</x-label>
                            <x-input type="number" name="amount" min="1" step="0.01" required />
                        </div>
                    </div>
                    <div>
                        <x-label>Description</x-label>
                        <x-input name="description" maxlength="300" placeholder="Optional" />
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                        <x-button type="submit">Record transfer</x-button>
                    </div>
                </form>
            </x-modal>
        </div>
    </template>

    {{-- Journal --}}
    <template x-if="form === 'journal'">
        <div x-data="{
                show: false,
                lines: [{ account_id: '', debit: '', credit: '', memo: '' }, { account_id: '', debit: '', credit: '', memo: '' }],
                get dr() { return this.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0) },
                get cr() { return this.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0) },
                get balanced() { return this.dr > 0 && Math.abs(this.dr - this.cr) < 0.005 },
                fmt(n) { return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 }) },
             }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) form = null })">
            <x-modal title="Journal entry" maxWidth="2xl">
                <form method="POST" action="{{ route('staff.books.journal.store') }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-gray-600">For anything the other forms don't cover: opening balances (e.g. bank against Owner's equity), capital, corrections. Debits must equal credits.</p>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <x-label :required="true">Date</x-label>
                            <x-input type="date" name="entry_date" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required />
                        </div>
                        <div class="col-span-2">
                            <x-label :required="true">Description</x-label>
                            <x-input name="description" maxlength="300" required />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="grid grid-cols-12 gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <span class="col-span-5">Account</span><span class="col-span-2 text-right">Debit</span><span class="col-span-2 text-right">Credit</span><span class="col-span-3">Memo</span>
                        </div>
                        <template x-for="(line, i) in lines" :key="i">
                            <div class="grid grid-cols-12 gap-2 items-center">
                                <select :name="`lines[${i}][account_id]`" x-model="line.account_id" class="col-span-5 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm" required>
                                    <option value="">Choose…</option>
                                    @foreach($journalAccounts as $a)<option value="{{ $a->id }}">{{ $a->code }} · {{ $a->name }}</option>@endforeach
                                </select>
                                <input type="number" min="0" step="0.01" :name="`lines[${i}][debit]`" x-model="line.debit" @input="if (Number(line.debit) > 0) line.credit = ''" class="col-span-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-right">
                                <input type="number" min="0" step="0.01" :name="`lines[${i}][credit]`" x-model="line.credit" @input="if (Number(line.credit) > 0) line.debit = ''" class="col-span-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-right">
                                <div class="col-span-3 flex items-center gap-1">
                                    <input type="text" maxlength="200" :name="`lines[${i}][memo]`" x-model="line.memo" class="min-w-0 flex-1 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                                    <button type="button" x-show="lines.length > 2" @click="lines.splice(i, 1)" class="p-1 text-gray-400 hover:text-red-600" title="Remove line">✕</button>
                                </div>
                            </div>
                        </template>
                        <div class="grid grid-cols-12 gap-2 items-center pt-2 border-t border-gray-100 text-sm">
                            <button type="button" class="col-span-5 text-left text-sm font-medium text-orange-600" @click="lines.push({ account_id: '', debit: '', credit: '', memo: '' })">+ Add line</button>
                            <span class="col-span-2 text-right font-semibold" x-text="fmt(dr)"></span>
                            <span class="col-span-2 text-right font-semibold" x-text="fmt(cr)"></span>
                            <span class="col-span-3 text-xs" :class="balanced ? 'text-green-700' : 'text-red-600'" x-text="balanced ? 'Balanced' : 'Debits must equal credits'"></span>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">Landlord funds can't be used here: they change only through collections and payouts.</p>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                        <x-button type="submit" x-bind:disabled="!balanced">Post entry</x-button>
                    </div>
                </form>
            </x-modal>
        </div>
    </template>
</div>
