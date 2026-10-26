<?php

namespace App\Traits;

use Illuminate\Support\Facades\Storage;

/**
 * A single optional photo stored on the public disk in the model's `photo`
 * column. Exposes `photo_url` (appended, so it reaches Alpine via Js::from)
 * and removes the file when the record is deleted.
 */
trait HasPhoto
{
    public static function bootHasPhoto(): void
    {
        static::deleted(function ($model) {
            if ($model->photo) {
                Storage::disk('public')->delete($model->photo);
            }
        });
    }

    public function initializeHasPhoto(): void
    {
        $this->append('photo_url');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo ? Storage::disk('public')->url($this->photo) : null;
    }
}
