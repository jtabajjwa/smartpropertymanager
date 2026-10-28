{{--
    Which tenant issue the job is for, and whether the tenant is told the vendor's name.
    $init: Alpine expression giving [issue_id, label, share_vendor] for the form.
--}}
<div class="rounded-xl border border-gray-200 p-4 space-y-3">
    <div>
        <x-label>Tenant issue</x-label>
        <x-search-select name="issue_id" url="{{ route('search.issues') }}" placeholder="Optional — the issue this job fixes" x-init="setValue(...{{ $init }}.slice(0, 2))" />
        <p class="mt-1 text-xs text-gray-500">The tenant sees in their portal when the repair is booked, moved, cancelled or done, with the date.</p>
    </div>
    <label class="flex items-start gap-2 text-sm text-gray-700">
        <input type="checkbox" name="share_vendor" value="1" x-bind:checked="{{ $init }}[2]" class="mt-0.5 rounded accent-orange-600">
        <span>Tell the tenant the vendor's name</span>
    </label>
</div>
