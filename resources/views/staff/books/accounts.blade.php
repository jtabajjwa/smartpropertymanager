<x-staff-layout>
    <x-slot name="header">Chart of accounts</x-slot>

    <div class="max-w-7xl mx-auto space-y-6" x-data="{ addModal: false, editing: null }">
        @include('staff.books._nav')

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500">Click an account to see every movement in it. Accounts marked <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs">auto</span> are posted to by the app.</p>
            @staffcan('books.post')
                <button type="button" @click="addModal = true" class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600">+ Add account</button>
            @endstaffcan
        </div>

        @foreach(\App\Models\LedgerAccount::TYPES as $type => [$label, $side, $range])
            @php $group = $accounts[$type] ?? collect(); @endphp
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-gray-100 flex items-center justify-between bg-gray-50/60">
                    <h2 class="text-sm font-semibold text-gray-900">{{ $label }} <span class="ml-2 text-xs font-normal text-gray-500">{{ $range }} · normal balance: {{ $side }}</span></h2>
                    <span class="text-sm font-semibold text-gray-900">{{ number_format($group->sum('balance')) }}</span>
                </div>
                @forelse($group as $a)
                    <div class="px-6 py-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }} flex items-center gap-4 text-sm {{ $a->is_active ? '' : 'opacity-60' }}">
                        <span class="w-14 shrink-0 font-mono text-gray-500">{{ $a->code }}</span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('staff.books.ledger', $a) }}" class="font-medium text-gray-900 hover:text-orange-600">{{ $a->name }}</a>
                            @if($a->system_key)<span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-500">auto</span>@endif
                            @if(! $a->is_active)<span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-500">inactive</span>@endif
                            @if($a->description)<p class="text-xs text-gray-500 truncate">{{ $a->description }}</p>@endif
                        </div>
                        <span class="font-semibold whitespace-nowrap {{ $a->balance < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($a->balance) }}</span>
                        @staffcan('books.post')
                            <button type="button" class="text-xs text-gray-400 hover:text-orange-600" @click="editing = {{ Js::from(['id' => $a->id, 'name' => $a->name, 'description' => $a->description, 'is_active' => $a->is_active, 'system' => (bool) $a->system_key]) }}">Edit</button>
                        @endstaffcan
                    </div>
                @empty
                    <p class="px-6 py-4 text-sm text-gray-500">None.</p>
                @endforelse
            </section>
        @endforeach

        @staffcan('books.post')
            <template x-if="addModal">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) addModal = false })">
                    <x-modal title="Add account">
                        <form method="POST" action="{{ route('staff.books.accounts.store') }}" class="space-y-4">
                            @csrf
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <x-label :required="true">Code</x-label>
                                    <x-input name="code" value="{{ old('code') }}" placeholder="5300" required />
                                </div>
                                <div class="col-span-2">
                                    <x-label :required="true">Type</x-label>
                                    <x-select name="type">
                                        @foreach(\App\Models\LedgerAccount::TYPES as $value => [$l, , $r])
                                            <option value="{{ $value }}" @selected(old('type', 'expense') === $value)>{{ $l }} ({{ $r }})</option>
                                        @endforeach
                                    </x-select>
                                </div>
                            </div>
                            <div>
                                <x-label :required="true">Name</x-label>
                                <x-input name="name" value="{{ old('name') }}" placeholder="e.g. Office rent" required />
                            </div>
                            <div>
                                <x-label>Description</x-label>
                                <x-input name="description" value="{{ old('description') }}" />
                            </div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Add account</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>

            <template x-if="editing">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editing = null })">
                    <x-modal title="Edit account">
                        <form method="POST" :action="'{{ url('staff/books/accounts') }}/' + editing.id" class="space-y-4">
                            @csrf @method('PUT')
                            <div>
                                <x-label :required="true">Name</x-label>
                                <x-input name="name" x-model="editing.name" required />
                            </div>
                            <div>
                                <x-label>Description</x-label>
                                <x-input name="description" x-model="editing.description" />
                            </div>
                            <label class="flex items-center gap-2 text-sm text-gray-700" x-show="!editing.system">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" x-model="editing.is_active" class="rounded border-gray-300 text-orange-600">
                                Active (shown in forms)
                            </label>
                            <p class="text-xs text-gray-500" x-show="editing.system">The app posts to this account automatically, so it always stays active.</p>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Save</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>
        @endstaffcan
    </div>
</x-staff-layout>
