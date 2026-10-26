<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores identification photos (estates, units) on the public disk.
 *
 * Phone photos are large, so images are scaled down to MAX_EDGE px and
 * re-encoded as JPEG to keep hosting space in check. If the image can't be
 * processed safely (no GD, unknown format, or a JPEG whose rotation can't be
 * read because the exif extension is missing), the original is stored as-is,
 * since browsers apply the EXIF rotation themselves.
 */
class PhotoUploader
{
    public const MAX_EDGE = 1600;

    public const QUALITY = 82;

    /** GD truecolor images use ~4 bytes per pixel; 5 leaves headroom. */
    protected const BYTES_PER_PIXEL = 5;

    /** Never raise memory_limit above this just to resize a photo. */
    protected const MAX_MEMORY = 512 * 1024 * 1024;

    /** Validation rules shared by every form that accepts a photo (10 MB). */
    public const RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'];

    /**
     * Apply the photo fields of a submitted form to $model: a new upload
     * replaces the old file, and a ticked "remove_photo" clears it.
     */
    public function sync(Request $request, Model $model, string $directory): void
    {
        $old = $model->photo;

        if ($request->hasFile('photo')) {
            $model->photo = $this->store($request->file('photo'), $directory);
        } elseif ($request->boolean('remove_photo')) {
            $model->photo = null;
        } else {
            return;
        }

        $model->save();

        if ($old && $old !== $model->photo) {
            Storage::disk('public')->delete($old);
        }
    }

    public function store(UploadedFile $file, string $directory): string
    {
        try {
            $processed = $this->resize($file);
        } catch (\Throwable $e) {
            Log::warning("Photo resize failed, storing original: {$e->getMessage()}");
            $processed = null;
        }

        if ($processed === null) {
            return $file->store($directory, 'public');
        }

        $path = trim($directory, '/').'/'.Str::random(40).'.jpg';
        Storage::disk('public')->put($path, $processed);

        return $path;
    }

    /**
     * @return string|null JPEG bytes, or null to keep the original file
     */
    protected function resize(UploadedFile $file): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $info = @getimagesize($file->getRealPath());
        if (!$info) {
            return null;
        }

        [$width, $height, $type] = $info;

        $isJpeg = $type === IMAGETYPE_JPEG;
        if ($isJpeg && !function_exists('exif_read_data')) {
            return null; // can't read rotation; let the browser handle it
        }

        // A decoded 12 MP phone photo needs ~60 MB, and rotating it briefly
        // holds two copies. Running out of memory is a fatal error that can't
        // be caught, so check first and keep the original if it won't fit.
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $needed = (int) ($width * $height * self::BYTES_PER_PIXEL * ($isJpeg ? 2 : 1)
            + ($width * $scale) * ($height * $scale) * self::BYTES_PER_PIXEL
            + 16 * 1024 * 1024);

        if (!$this->ensureMemory($needed)) {
            return null;
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file->getRealPath()) : false,
            default => false,
        };

        if (!$image) {
            return null;
        }

        if ($isJpeg) {
            $image = $this->applyExifOrientation($image, $file->getRealPath());
            $width = imagesx($image);
            $height = imagesy($image);
        }

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // White canvas so transparent PNG/WebP areas don't turn black in JPEG.
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, self::QUALITY);
        $bytes = ob_get_clean();

        unset($image, $canvas); // GdImage objects are freed once unreferenced

        return $bytes ?: null;
    }

    /**
     * Make sure $bytes more memory is available, raising this request's
     * memory_limit (up to MAX_MEMORY) if needed. False means "don't try".
     */
    protected function ensureMemory(int $bytes): bool
    {
        $limit = $this->toBytes((string) ini_get('memory_limit'));
        if ($limit < 0) {
            return true; // unlimited
        }

        $required = memory_get_usage(true) + $bytes;
        if ($required <= $limit) {
            return true;
        }

        if ($required > self::MAX_MEMORY) {
            return false;
        }

        return ini_set('memory_limit', (string) $required) !== false
            && $this->toBytes((string) ini_get('memory_limit')) >= $required;
    }

    protected function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Rotate/flip a JPEG to match the camera's EXIF orientation tag. */
    protected function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

        // The caller reassigns its variable to the result, which frees the
        // unrotated copy as soon as this returns.
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }
}
