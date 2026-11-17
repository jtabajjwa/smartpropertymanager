{{-- Optional supporting documents for a books form (needs enctype="multipart/form-data"). --}}
<div x-data="{ names: [] }">
    <x-label>Supporting documents</x-label>
    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-600 hover:border-orange-300">
        <svg class="h-5 w-5 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
        <span class="min-w-0 truncate" x-text="names.length ? names.join(', ') : 'Attach receipts, invoices or statements (optional)'"></span>
        <input type="file" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx,.csv,.txt" class="sr-only"
               @change="names = Array.from($event.target.files).map(f => f.name)">
    </label>
    <p class="mt-1 text-xs text-gray-500">Up to {{ \App\Services\JournalAttachments::MAX_FILES }} files, 10 MB each: PDF, photos, Word, Excel or CSV. Only staff can open them.</p>
</div>
