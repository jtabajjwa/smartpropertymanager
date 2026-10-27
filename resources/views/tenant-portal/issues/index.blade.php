<x-tenant-layout title="Issues" subtitle="Repairs, requests and complaints you've sent your landlord">
    @php $th = 'px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider'; @endphp

    <x-slot name="actions">
        <a href="{{ route('tenant.issues.create') }}" class="inline-flex items-center rounded-xl bg-orange-500 hover:bg-orange-600 px-4 py-2 text-sm font-semibold text-white transition">+ Report an issue</a>
    </x-slot>

    <div class="flex items-center justify-between mb-4 lg:hidden">
        <h1 class="text-xl font-bold text-gray-900">Issues</h1>
        <a href="{{ route('tenant.issues.create') }}" class="inline-flex items-center rounded-xl bg-orange-500 hover:bg-orange-600 px-4 py-2 text-sm font-semibold text-white transition">+ Report an issue</a>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 lg:shadow-sm overflow-hidden">
        @if($issues->isEmpty())
            <div class="px-4 py-12 text-center">
                <p class="text-sm text-gray-500">You haven't reported any issues.</p>
                <p class="mt-1 text-sm text-gray-500">A leaking pipe, a broken lock, a request or a complaint? <a href="{{ route('tenant.issues.create') }}" class="font-medium text-orange-600">Let your landlord know</a>.</p>
            </div>
        @else
            <div class="lg:hidden">
                @foreach($issues as $issue)
                    <a href="{{ route('tenant.issues.show', $issue->id) }}" class="px-4 py-3.5 flex items-center gap-3 hover:bg-gray-50 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $issue->title }}</p>
                            <p class="text-xs text-gray-500">{{ $issue->number }} · {{ $issue->categoryLabel() }} · {{ $issue->created_at->format('d M Y') }}</p>
                        </div>
                        <x-badge :color="$issue->statusColor()">{{ $issue->statusLabel() }}</x-badge>
                    </a>
                @endforeach
            </div>
            <table class="hidden lg:table min-w-full">
                <thead class="bg-gray-50/60 border-b border-gray-200">
                    <tr>
                        <th class="{{ $th }}">Issue</th>
                        <th class="{{ $th }}">Type</th>
                        <th class="{{ $th }}">Reported</th>
                        <th class="{{ $th }}">Last update</th>
                        <th class="{{ $th }}">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($issues as $issue)
                        <tr class="hover:bg-gray-50/60 cursor-pointer" onclick="this.querySelector('a').click()">
                            <td class="px-5 py-3.5">
                                <a href="{{ route('tenant.issues.show', $issue->id) }}" class="text-sm font-medium text-gray-900 hover:text-orange-600">{{ $issue->title }}</a>
                                <p class="text-xs text-gray-500">{{ $issue->number }}@if($issue->photo) · photo attached @endif</p>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-gray-600">{{ $issue->categoryLabel() }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-600 whitespace-nowrap">{{ $issue->created_at->format('d M Y') }}</td>
                            <td class="px-5 py-3.5 text-sm text-gray-600 whitespace-nowrap">{{ $issue->updated_at->diffForHumans() }}</td>
                            <td class="px-5 py-3.5"><x-badge :color="$issue->statusColor()">{{ $issue->statusLabel() }}</x-badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if($issues->hasPages())
        <div class="mt-4">{{ $issues->links() }}</div>
    @endif
</x-tenant-layout>
