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

        {{-- Every job booked for it is done: suggest resolving (never automatic) --}}
        @if($issue->isOpen() && $issue->jobsAllDone())
            <form method="POST" action="{{ route('issues.update', $issue) }}" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-green-200 bg-green-50 px-5 py-4">
                @csrf @method('PUT')
                <input type="hidden" name="status" value="resolved">
                <input type="hidden" name="note" value="The repair work is complete.">
                <p class="text-sm text-gray-700"><span class="font-semibold text-gray-900">All job cards are completed.</span> Mark this issue resolved? The tenant will see it in their portal.</p>
                <x-button type="submit">Mark resolved</x-button>
            </form>
        @endif

        {{-- Job cards booked for this issue --}}
        <div class="bg-white rounded-2xl border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Job cards</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Vendors booked to fix this. The tenant is told the dates.</p>
                </div>
                @if($canBookJobs)
                    @if($issue->isOpen())
                        <a href="{{ route('vendor-jobs.index', ['issue' => $issue->id]) }}" class="inline-flex items-center rounded-xl bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700 transition">Create job card</a>
                    @endif
                @elseif(auth()->user()->isLandlordAdmin())
                    <a href="{{ route('subscription.show') }}" class="text-sm font-medium text-orange-600 hover:text-orange-700">Job cards are on a higher plan &rarr;</a>
                @else
                    <span class="text-sm text-gray-500">Job cards aren't on your plan.</span>
                @endif
            </div>
            @if($issue->jobs->isEmpty())
                <p class="px-6 py-4 text-sm text-gray-500">No job cards yet.</p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach($issue->jobs as $job)
                        <li class="px-6 py-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span class="font-medium text-gray-900">{{ $job->number }}</span>
                            <span class="text-gray-600">{{ $job->vendor?->name }}</span>
                            <span class="text-gray-500">{{ $job->job_date->format('j M Y') }}</span>
                            <x-badge :color="['scheduled' => 'gray', 'in_progress' => 'blue', 'completed' => 'green', 'cancelled' => 'red'][$job->status] ?? 'gray'">{{ $job->status_label }}</x-badge>
                            <span class="ml-auto font-medium text-gray-900 tabular-nums">{{ $job->cost !== null ? 'UGX '.number_format((float) $job->cost) : '--' }}</span>
                        </li>
                    @endforeach
                </ul>
                @php $jobsCost = $issue->jobs->where('status', '!=', 'cancelled')->sum(fn ($j) => (float) $j->cost); @endphp
                <div class="px-6 py-3 border-t border-gray-100 flex justify-between text-sm">
                    <span class="text-gray-500">Total cost (not shown to the tenant)</span>
                    <span class="font-semibold text-gray-900 tabular-nums">UGX {{ number_format($jobsCost) }}</span>
                </div>
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
