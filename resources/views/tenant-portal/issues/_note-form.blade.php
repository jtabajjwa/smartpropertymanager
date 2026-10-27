    @if($issue->status !== 'closed')
        <form method="POST" action="{{ route('tenant.issues.comment', $issue->id) }}" class="bg-white rounded-2xl border border-gray-200 p-4 lg:p-6 lg:shadow-sm space-y-3">
            @csrf
            <x-label>{{ $issue->status === 'resolved' ? 'Not fixed? Tell your landlord (this re-opens the issue)' : 'Add a note for your landlord' }}</x-label>
            <x-textarea name="note" rows="3" maxlength="2000" required placeholder="e.g. It is still leaking this morning."></x-textarea>
            @error('note')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex justify-end"><x-button type="submit">Send note</x-button></div>
        </form>
    @endif
