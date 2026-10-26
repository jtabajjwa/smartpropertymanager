<x-app-layout>
    <x-slot name="header">Tenant Issues</x-slot>

    @php
        $filters = ['active' => 'Open & in progress', 'open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'];
    @endphp

    <div class="max-w-7xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Tenant Issues</h1>
            <p class="mt-1 text-sm text-gray-500">Repairs, requests and complaints tenants raise in the tenant portal. They see every status change and note you add.</p>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            @foreach(\App\Models\Issue::STATUSES as $key => [$label, $color])
                <a href="{{ route('issues.index', ['status' => $key]) }}" class="bg-white rounded-2xl border border-gray-200 p-4 hover:border-orange-300 transition">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">{{ $label }}</p>
                    <p class="text-2xl font-bold mt-1 {{ $key === 'open' && ($counts[$key] ?? 0) ? 'text-orange-600' : 'text-gray-900' }}">{{ number_format($counts[$key] ?? 0) }}</p>
                </a>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 p-4">
            <form method="GET" action="{{ route('issues.index') }}" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[200px]">
                    <x-label>Search</x-label>
                    <x-input name="search" value="{{ request('search') }}" placeholder="Title, number or tenant…" />
                </div>
                <div class="w-48">
                    <x-label>Status</x-label>
                    <x-select name="status">
                        @foreach($filters as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="w-48">
                    <x-label>Type</x-label>
                    <x-select name="category">
                        <option value="">All types</option>
                        @foreach(\App\Models\Issue::CATEGORIES as $value => $label)
                            <option value="{{ $value }}" @selected(request('category') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <x-button type="submit">Filter</x-button>
            </form>
        </div>

        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200">
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Issue</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Tenant / unit</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reported</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($issues as $issue)
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('issues.show', $issue) }}'">
                                <td class="px-5 py-3">
                                    <a href="{{ route('issues.show', $issue) }}" class="font-medium text-gray-900 hover:text-orange-600">{{ $issue->title }}</a>
                                    <span class="block text-xs text-gray-500">{{ $issue->number }} · {{ $issue->categoryLabel() }}@if($issue->photo) · 📷 photo @endif</span>
                                </td>
                                <td class="px-5 py-3 text-gray-700">
                                    {{ $issue->tenant?->name }}
                                    @if($issue->unit)<span class="block text-xs text-gray-500">{{ $issue->unit->name }}{{ $issue->unit->estate ? ' · '.$issue->unit->estate->name : '' }}</span>@endif
                                </td>
                                <td class="px-5 py-3 text-gray-600 whitespace-nowrap" title="{{ $issue->created_at->format('d M Y H:i') }}">{{ $issue->created_at->diffForHumans() }}</td>
                                <td class="px-5 py-3"><x-badge :color="$issue->statusColor()">{{ $issue->statusLabel() }}</x-badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">No issues here.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($issues->hasPages())
                <div class="px-5 py-4 border-t border-gray-200">{{ $issues->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
