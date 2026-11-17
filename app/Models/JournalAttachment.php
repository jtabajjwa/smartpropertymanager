<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A receipt, invoice or other document kept with a journal entry (private disk). */
class JournalAttachment extends Model
{
    public const DISK = 'local';

    protected $fillable = ['journal_entry_id', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by_staff_user_id'];

    protected static function booted(): void
    {
        static::deleted(fn (self $a) => Storage::disk(self::DISK)->delete($a->path));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'uploaded_by_staff_user_id');
    }

    /** PDFs and images open in the browser; anything else downloads. */
    public function opensInBrowser(): bool
    {
        return $this->mime_type === 'application/pdf' || str_starts_with((string) $this->mime_type, 'image/');
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? round($this->size / 1048576, 1).' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }
}
