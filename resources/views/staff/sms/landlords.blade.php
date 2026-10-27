<x-staff-layout>
    <x-slot name="header">SMS</x-slot>

    <div class="max-w-7xl mx-auto">
        @include('staff.sms._tabs')

        <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-4">
            <form method="GET" action="{{ route('staff.sms.landlords') }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[220px]">
                    <x-label>Search landlord</x-label>
                    <x-input name="search" value="{{ request('search') }}" placeholder="Name, email or phone…" />
                </div>
                <div class="w-56">
                    <x-label>Balance</x-label>
                    <x-select name="balance">
                        <option value="">All</option>
                        <option value="low" @selected(request('balance') === 'low')>Low ({{ $threshold }} or fewer)</option>
                        <option value="empty" @selected(request('balance') === 'empty')>Out of credit</option>
                    </x-select>
                </div>
                <div class="flex gap-2">
                    <x-button type="submit">Filter</x-button>
                    @if(request()->hasAny(['search', 'balance']))
                        <a href="{{ route('staff.sms.landlords') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Clear</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Landlord</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Balance</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Used this month</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Bought (all time)</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Last bought</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($landlords as $landlord)
                            <tr class="hover:bg-gray-50 transition-colors cursor-pointer" onclick="if (event.target.closest('a, button, form')) return; this.querySelector('a[href]').click()">
                                <td class="px-5 py-3 text-sm">
                                    <a href="{{ route('staff.sms.landlord', $landlord) }}" class="font-medium text-gray-900 hover:text-orange-600">{{ $landlord->name }}</a>
                                    <span class="block text-xs text-gray-400">{{ $landlord->contact_email }}</span>
                                </td>
                                <td class="px-5 py-3 text-sm text-right whitespace-nowrap">
                                    <span class="font-semibold {{ $landlord->sms_balance <= 0 ? 'text-red-600' : ($landlord->sms_balance <= $threshold ? 'text-orange-600' : 'text-gray-900') }}">{{ number_format($landlord->sms_balance) }}</span>
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-700 text-right">{{ number_format(-(int) $landlord->used_month) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-700 text-right">{{ number_format((int) $landlord->bought_total) }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $landlord->last_bought_at ? \Carbon\Carbon::parse($landlord->last_bought_at)->format('d M Y') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-gray-500">No landlords match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($landlords->hasPages())
                <div class="px-5 py-4 border-t border-gray-200">{{ $landlords->links() }}</div>
            @endif
        </div>
    </div>
</x-staff-layout>
