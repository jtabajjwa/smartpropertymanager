<x-staff-layout>
    <x-slot name="header">Journal</x-slot>

    <div class="max-w-7xl mx-auto space-y-6">
        @include('staff.books._nav')

        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="period" value="{{ $period }}">
                @if($period === 'custom')<input type="hidden" name="from" value="{{ $from?->toDateString() }}"><input type="hidden" name="to" value="{{ $to?->toDateString() }}">@endif
                <div class="w-60"><x-label>Search</x-label><x-input name="search" value="{{ request('search') }}" placeholder="Description or JE number" /></div>
                <div class="w-48">
                    <x-label>Source</x-label>
                    <x-select name="source" onchange="this.form.requestSubmit()">
                        <option value="">All</option>
                        @foreach(['payment_transaction' => 'Payments received', 'liquidation' => 'Landlord payouts', 'sms_purchase' => 'SMS purchases', 'landlord_settlement' => 'Settlements', 'manual' => 'Manual entries'] as $v => $l)
                            <option value="{{ $v }}" @selected(request('source') === $v)>{{ $l }}</option>
                        @endforeach
                    </x-select>
                </div>
                <x-button type="submit">Filter</x-button>
            </form>
            @include('staff.books._period')
        </div>

        <div class="space-y-3">
            @forelse($entries as $e)
                <article class="bg-white rounded-2xl border border-gray-200 shadow-sm {{ $e->reversed_at ? 'opacity-60' : '' }}" x-data="{ reversing: false, attaching: false }">
                    <div class="px-5 py-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-gray-100">
                        <span class="font-mono text-sm text-gray-500">{{ $e->number }}</span>
                        <span class="text-sm text-gray-500">{{ $e->entry_date->format('d M Y') }}</span>
                        <span class="text-sm font-medium text-gray-900">{{ $e->description }}</span>
                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-500">{{ $e->sourceLabel() }}</span>
                        @if($e->reversed_at)<x-badge color="gray">Reversed</x-badge>@endif
                        @if($e->createdBy)<span class="text-xs text-gray-400">by {{ $e->createdBy->name }}</span>@endif
                        @staffcan('books.post')
                            @if($e->isManual() && ! $e->reversed_at && ! $e->reverses_entry_id)
                                <button type="button" class="ml-auto text-xs text-gray-400 hover:text-red-600" x-show="!reversing" @click="reversing = true">Reverse…</button>
                                <form method="POST" action="{{ route('staff.books.journal.reverse', $e) }}" class="ml-auto flex gap-2" x-show="reversing" x-cloak>
                                    @csrf
                                    <x-input name="reason" placeholder="Reason" required minlength="3" class="w-56" />
                                    <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Reverse</button>
                                </form>
                            @endif
                        @endstaffcan
                    </div>
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-50">
                            @foreach($e->lines as $line)
                                <tr>
                                    <td class="px-5 py-2 {{ (float) $line->credit ? 'pl-12' : '' }}">
                                        <a href="{{ route('staff.books.ledger', $line->account) }}" class="text-gray-700 hover:text-orange-600"><span class="font-mono text-gray-400">{{ $line->account->code }}</span> {{ $line->account->name }}</a>
                                        @if($line->landlord)<span class="text-xs text-gray-500"> · {{ $line->landlord->name }}</span>@endif
                                        @if($line->memo)<span class="text-xs text-gray-400"> · {{ $line->memo }}</span>@endif
                                    </td>
                                    <td class="px-5 py-2 text-right w-36 whitespace-nowrap">{{ (float) $line->debit ? number_format((float) $line->debit, 2) : '' }}</td>
                                    <td class="px-5 py-2 text-right w-36 whitespace-nowrap">{{ (float) $line->credit ? number_format((float) $line->credit, 2) : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    {{-- Supporting documents --}}
                    @if($e->attachments->isNotEmpty() || auth('staff')->user()?->hasPermission('books.post'))
                        <div class="px-5 py-2.5 border-t border-gray-100 flex flex-wrap items-center gap-2 text-xs">
                            @foreach($e->attachments as $doc)
                                <span class="inline-flex max-w-xs items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 py-1 pl-2 pr-1">
                                    <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
                                    <a href="{{ route('staff.books.attachments.show', $doc) }}" target="_blank" hx-boost="false" class="truncate font-medium text-gray-700 hover:text-orange-600"
                                       title="{{ $doc->original_name }} · {{ $doc->sizeLabel() }}{{ $doc->uploadedBy ? ' · added by '.$doc->uploadedBy->name : '' }} · {{ $doc->created_at->format('d M Y H:i') }}">{{ $doc->original_name }}</a>
                                    <span class="shrink-0 text-gray-400">{{ $doc->sizeLabel() }}</span>
                                    @staffcan('books.post')
                                        <form method="POST" action="{{ route('staff.books.attachments.destroy', $doc) }}" @submit.prevent="$store.confirm.open('Remove {{ addslashes($doc->original_name) }} from {{ $e->number }}? The file is deleted.', $el)">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="rounded p-0.5 text-gray-400 hover:text-red-600" title="Remove">✕</button>
                                        </form>
                                    @endstaffcan
                                </span>
                            @endforeach
                            @staffcan('books.post')
                                <button type="button" x-show="!attaching" @click="attaching = true" class="font-medium text-orange-600 hover:text-orange-700">+ Attach document</button>
                                <form method="POST" action="{{ route('staff.books.journal.attach', $e) }}" enctype="multipart/form-data" x-show="attaching" x-cloak class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    <input type="file" name="attachments[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx,.csv,.txt" class="text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-gray-100 file:px-2.5 file:py-1 file:text-xs file:font-medium">
                                    <button type="submit" class="rounded-lg bg-orange-600 px-3 py-1 text-xs font-semibold text-white hover:bg-orange-700">Upload</button>
                                    <button type="button" @click="attaching = false" class="text-gray-500 hover:text-gray-800">Cancel</button>
                                </form>
                            @endstaffcan
                        </div>
                    @endif
                </article>
            @empty
                <div class="bg-white rounded-2xl border border-gray-200 px-6 py-12 text-center text-sm text-gray-500">No entries in this period.</div>
            @endforelse
        </div>

        @if($entries->hasPages())
            <div>{{ $entries->links() }}</div>
        @endif
    </div>
</x-staff-layout>
