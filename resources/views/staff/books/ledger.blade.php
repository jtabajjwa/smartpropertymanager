<x-staff-layout>
    <x-slot name="header">{{ $account->code }} {{ $account->name }}</x-slot>

    <div class="max-w-7xl mx-auto space-y-6">
        @include('staff.books._nav')

        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('staff.books.accounts') }}" class="text-sm text-gray-500 hover:text-gray-900">← Chart of accounts</a>
                <h2 class="mt-1 text-xl font-bold text-gray-900"><span class="font-mono text-gray-400">{{ $account->code }}</span> {{ $account->name }}</h2>
                <p class="text-sm text-gray-500">{{ $account->typeLabel() }}{{ $account->description ? ' · '.$account->description : '' }}@if($landlordId) · <strong>{{ $landlordNames[$landlordId] ?? 'Landlord #'.$landlordId }}</strong> only (<a href="{{ route('staff.books.ledger', $account) }}" class="text-orange-600">all</a>)@endif</p>
            </div>
            @include('staff.books._period')
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-{{ $perLandlord->isNotEmpty() ? '3' : '1' }} gap-6 items-start">
            <section class="{{ $perLandlord->isNotEmpty() ? 'lg:col-span-2' : '' }} bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50/60 border-b border-gray-200">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Debit</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Credit</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @if($from)
                                <tr class="bg-gray-50/40"><td class="px-5 py-2.5 text-gray-500">{{ $from->format('d M Y') }}</td><td class="px-5 py-2.5 text-gray-500" colspan="3">Opening balance</td><td class="px-5 py-2.5 text-right font-medium">{{ number_format($opening) }}</td></tr>
                            @endif
                            @forelse($lines as $line)
                                <tr>
                                    <td class="px-5 py-2.5 text-gray-600 whitespace-nowrap">{{ $line->entry->entry_date->format('d M Y') }}</td>
                                    <td class="px-5 py-2.5">
                                        <span class="text-gray-900">{{ $line->entry->description }}</span>
                                        <span class="block text-xs text-gray-400">{{ $line->entry->number }}@if($line->landlord) · {{ $line->landlord->name }}@endif @if($line->memo) · {{ $line->memo }}@endif</span>
                                    </td>
                                    <td class="px-5 py-2.5 text-right whitespace-nowrap">{{ (float) $line->debit ? number_format((float) $line->debit) : '' }}</td>
                                    <td class="px-5 py-2.5 text-right whitespace-nowrap">{{ (float) $line->credit ? number_format((float) $line->credit) : '' }}</td>
                                    <td class="px-5 py-2.5 text-right whitespace-nowrap font-medium {{ $line->running < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($line->running) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">No movements in this period.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="border-t border-gray-200">
                            <tr><td></td><td class="px-5 py-3 font-semibold text-gray-900" colspan="3">Closing balance</td><td class="px-5 py-3 text-right font-bold text-gray-900">{{ number_format($closing) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            @if($perLandlord->isNotEmpty())
                <section class="bg-white rounded-2xl border border-gray-200 shadow-sm">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-900">Owed to each landlord (today)</h3>
                    </div>
                    @foreach($perLandlord as $id => $owed)
                        <a href="{{ route('staff.books.ledger', [$account, 'landlord' => $id, 'period' => 'all']) }}" class="px-5 py-2.5 flex justify-between gap-3 text-sm hover:bg-gray-50 {{ ! $loop->last ? 'border-b border-gray-100' : '' }} {{ $landlordId === $id ? 'bg-orange-50/50' : '' }}">
                            <span class="text-gray-700 truncate">{{ $landlordNames[$id] ?? '#'.$id }}</span>
                            <span class="font-medium text-gray-900 whitespace-nowrap">{{ number_format($owed) }}</span>
                        </a>
                    @endforeach
                </section>
            @endif
        </div>
    </div>
</x-staff-layout>
