<x-tenant-layout :title="$issue->title" :subtitle="$issue->number.' · '.$issue->categoryLabel()">
    <x-slot name="actions"><a href="{{ route('tenant.issues.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-900">← All issues</a></x-slot>

    <a href="{{ route('tenant.issues.index') }}" class="inline-block mb-3 text-sm text-gray-500 hover:text-gray-900 lg:hidden">← Issues</a>

    <div class="lg:grid lg:grid-cols-5 lg:gap-6 lg:items-start">
    <div class="lg:col-span-3 space-y-6">
    <div class="bg-white rounded-2xl border border-gray-200 p-4 lg:p-6 lg:shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-orange-600">{{ $issue->categoryLabel() }} · {{ $issue->number }}</p>
                <h1 class="mt-1 text-lg font-bold text-gray-900">{{ $issue->title }}</h1>
                <p class="text-xs text-gray-500">Reported {{ $issue->created_at->format('d M Y, H:i') }}@if($issue->unit) · Unit {{ $issue->unit->name }}@endif</p>
            </div>
            <x-badge :color="$issue->statusColor()">{{ $issue->statusLabel() }}</x-badge>
        </div>
        <p class="mt-4 text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{{ $issue->description }}</p>
        @if($issue->photo)
            <a href="{{ Storage::url($issue->photo) }}" target="_blank"><img src="{{ Storage::url($issue->photo) }}" alt="Photo you attached" class="mt-4 max-h-72 rounded-xl border border-gray-200"></a>
        @endif
    </div>

    @include('tenant-portal.issues._note-form')
    </div>

    <div class="lg:col-span-2 mt-6 lg:mt-0 lg:bg-white lg:rounded-2xl lg:border lg:border-gray-200 lg:shadow-sm lg:p-6">
    <h2 class="mb-3 font-semibold text-gray-900">Updates</h2>
    <ol class="relative border-l-2 border-gray-200 ml-2 space-y-4">
        @foreach($issue->updates as $update)
            <li class="ml-4">
                <span class="absolute -left-[7px] mt-1.5 w-3 h-3 rounded-full {{ $update->author === 'tenant' ? 'bg-gray-300' : 'bg-orange-500' }}"></span>
                <p class="text-xs text-gray-500">{{ $update->created_at->format('d M Y, H:i') }} · {{ $update->author === 'tenant' ? 'You' : $portalTenant->landlord->name }}</p>
                @if($update->status)
                    <p class="mt-0.5 text-sm text-gray-900">
                        @if($update->note === 'Reported') Reported @else Status: <strong>{{ \App\Models\Issue::STATUSES[$update->status][0] ?? $update->status }}</strong> @endif
                    </p>
                @endif
                @if($update->note && $update->note !== 'Reported')
                    <p class="mt-1 text-sm text-gray-700 whitespace-pre-wrap rounded-xl {{ $update->author === 'tenant' ? 'bg-gray-100' : 'bg-orange-50' }} px-3 py-2">{{ $update->note }}</p>
                @endif
            </li>
        @endforeach
    </ol>

    </div>
    </div>
</x-tenant-layout>
