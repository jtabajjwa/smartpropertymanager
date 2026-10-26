<x-app-layout>
    <x-slot name="header">Tenant Issues</x-slot>

    <div class="max-w-4xl mx-auto space-y-6">
        <a href="{{ route('issues.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900">← All issues</a>

        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-orange-600">{{ $issue->categoryLabel() }} · {{ $issue->number }}</p>
                    <h1 class="mt-1 text-xl font-bold text-gray-900">{{ $issue->title }}</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $issue->tenant?->name }} · {{ $issue->tenant?->primary_phone }}
                        @if($issue->unit) · {{ $issue->unit->name }}{{ $issue->unit->estate ? ', '.$issue->unit->estate->name : '' }} @endif
                        · reported {{ $issue->created_at->format('d M Y, H:i') }}
                    </p>
                </div>
                <x-badge :color="$issue->statusColor()">{{ $issue->statusLabel() }}</x-badge>
            </div>

            <p class="mt-5 text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{{ $issue->description }}</p>

            @if($issue->photo)
                <a href="{{ Storage::url($issue->photo) }}" target="_blank" class="mt-5 block">
                    <img src="{{ Storage::url($issue->photo) }}" alt="Photo of the issue" class="max-h-96 rounded-xl border border-gray-200">
                </a>
            @endif
        </div>

        {{-- Log --}}
        <div class="bg-white rounded-2xl border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-base font-semibold text-gray-900">History</h3>
                <p class="mt-0.5 text-sm text-gray-500">The tenant sees this too.</p>
            </div>
            <ol class="divide-y divide-gray-100">
                @foreach($issue->updates as $update)
                    <li class="px-6 py-3 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-gray-900">
                                @if($update->author === 'tenant') {{ $issue->tenant?->name }} (tenant)
                                @elseif($update->author === 'staff') {{ $update->staffUser?->name ?? 'Primora staff' }} (Primora)
                                @else {{ $update->user?->name ?? 'Landlord' }} @endif
                            </span>
                            @if($update->status)
                                <span class="text-gray-500">set status to</span>
                                <x-badge :color="\App\Models\Issue::STATUSES[$update->status][1] ?? 'gray'">{{ \App\Models\Issue::STATUSES[$update->status][0] ?? $update->status }}</x-badge>
                            @endif
                            <span class="ml-auto text-xs text-gray-400">{{ $update->created_at->format('d M Y, H:i') }}</span>
                        </div>
                        @if($update->note && $update->note !== 'Reported')
                            <p class="mt-1 text-gray-700 whitespace-pre-wrap">{{ $update->note }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>

        {{-- Respond --}}
        <form method="POST" action="{{ route('issues.update', $issue) }}" class="bg-white rounded-2xl border border-gray-200 p-6 space-y-4">
            @csrf @method('PUT')
            <h3 class="text-base font-semibold text-gray-900">Update the tenant</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <x-label :required="true">Status</x-label>
                    <x-select name="status">
                        @foreach(\App\Models\Issue::STATUSES as $value => [$label])
                            <option value="{{ $value }}" @selected(old('status', $issue->status) === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div class="sm:col-span-2">
                    <x-label>Note for the tenant</x-label>
                    <x-textarea name="note" rows="3" placeholder="e.g. A plumber will come on Thursday morning.">{{ old('note') }}</x-textarea>
                </div>
            </div>
            <div class="flex justify-end"><x-button type="submit">Save update</x-button></div>
        </form>
    </div>
</x-app-layout>
