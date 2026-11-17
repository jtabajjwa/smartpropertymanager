<?php

namespace App\Services;

use App\Models\JournalAttachment;
use App\Models\JournalEntry;
use App\Models\StaffUser;
use Illuminate\Http\UploadedFile;

/** Keeping supporting documents with Primora's journal entries. */
class JournalAttachments
{
    public const MAX_FILES = 5;
    public const MAX_KB = 10240;
    public const MIMES = 'pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx,csv,txt';

    /** Validation rules for an optional `attachments[]` upload. */
    public static function rules(bool $required = false): array
    {
        return [
            'attachments' => [$required ? 'required' : 'nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'max:'.self::MAX_KB, 'mimes:'.self::MIMES],
        ];
    }

    public static function messages(): array
    {
        return [
            'attachments.required' => 'Choose at least one document to attach.',
            'attachments.max' => 'Attach at most '.self::MAX_FILES.' documents at a time.',
            'attachments.*.max' => 'Each document must be 10 MB or smaller.',
            'attachments.*.mimes' => 'Documents must be PDF, images, Word, Excel, CSV or text files.',
        ];
    }

    /** @param  list<UploadedFile>  $files */
    public function store(JournalEntry $entry, array $files, ?StaffUser $by): int
    {
        $count = 0;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }
            $path = $file->store('books/'.$entry->entry_date->format('Y/m'), JournalAttachment::DISK);
            $entry->attachments()->create([
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'uploaded_by_staff_user_id' => $by?->id,
            ]);
            $count++;
        }

        return $count;
    }
}
