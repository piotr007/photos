<?php

namespace App\Support;

use App\Models\Photo;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;

/**
 * Photo processing pipeline: raw upload -> 3 WebP derivatives (large/medium/thumb)
 * + EXIF extraction. The original file is deleted afterwards (project decision).
 *
 * Paths are always relative to the media disk root (config('media.disk')).
 */
final class PhotoMedia
{
    /**
     * Process a raw uploaded image stored on the media disk.
     *
     * @return array<string, mixed> Photo attributes: path_large, path_medium, path_thumb, width, height + EXIF fields.
     */
    public static function process(string $sourcePath, ?string $disk = null): array
    {
        $storage = Storage::disk($disk ?? config('media.disk'));

        self::ensureMemoryLimit();

        [$absolute, $isTemp] = self::localCopy($storage, $sourcePath);

        try {
            // EXIF must be read from the original: derivatives are re-encoded with metadata stripped (privacy / GPS).
            $exif = self::readExif($absolute);

            $base = config('media.photos_dir').'/'.Str::lower((string) Str::ulid());
            $quality = config('media.quality');

            // Decoding applies EXIF auto-orientation (driver default). Shrink the same
            // image in place large -> medium -> thumb to keep peak memory low.
            $image = self::manager()->read($absolute);

            $image->scaleDown(config('media.large_max'), config('media.large_max'));
            $width = $image->width();
            $height = $image->height();
            $storage->put("{$base}-large.webp", (string) $image->toWebp(quality: $quality['large']));

            $image->scaleDown(config('media.medium_max'), config('media.medium_max'));
            $storage->put("{$base}-medium.webp", (string) $image->toWebp(quality: $quality['medium']));

            $thumbSize = config('media.thumb_size');
            $image->cover($thumbSize, $thumbSize);
            $storage->put("{$base}-thumb.webp", (string) $image->toWebp(quality: $quality['thumb']));
        } catch (Throwable $exception) {
            // Undecodable / corrupt upload: leave no orphaned files behind, then let the caller report it.
            if (isset($base)) {
                self::deletePaths(["{$base}-large.webp", "{$base}-medium.webp", "{$base}-thumb.webp"], $disk);
            }
            $storage->delete($sourcePath);

            throw $exception;
        } finally {
            if ($isTemp) {
                @unlink($absolute);
            }
        }

        $storage->delete($sourcePath);

        return [
            'path_large' => "{$base}-large.webp",
            'path_medium' => "{$base}-medium.webp",
            'path_thumb' => "{$base}-thumb.webp",
            'width' => $width,
            'height' => $height,
        ] + $exif;
    }

    /**
     * Remove all derivatives of a photo from the media disk.
     */
    public static function delete(Photo $photo, ?string $disk = null): void
    {
        self::deletePaths([$photo->path_large, $photo->path_medium, $photo->path_thumb], $disk);
    }

    /**
     * @param  array<int, string|null>  $paths
     */
    public static function deletePaths(array $paths, ?string $disk = null): void
    {
        $paths = array_values(array_filter($paths, fn ($path) => is_string($path) && filled($path)));

        if ($paths !== []) {
            Storage::disk($disk ?? config('media.disk'))->delete($paths);
        }
    }

    /**
     * Shooting metadata from EXIF, normalised for display. Missing values are null.
     *
     * @return array{camera: ?string, lens: ?string, focal_length: ?string, aperture: ?string, shutter_speed: ?string, iso: ?int, taken_at: ?Carbon}
     */
    public static function readExif(string $absolutePath): array
    {
        $empty = [
            'camera' => null,
            'lens' => null,
            'focal_length' => null,
            'aperture' => null,
            'shutter_speed' => null,
            'iso' => null,
            'taken_at' => null,
        ];

        if (! function_exists('exif_read_data')) {
            return $empty;
        }

        try {
            $data = @exif_read_data($absolutePath, 'ANY_TAG', true) ?: [];
        } catch (Throwable) {
            return $empty;
        }

        $ifd0 = $data['IFD0'] ?? [];
        $exif = $data['EXIF'] ?? [];

        $make = self::cleanString($ifd0['Make'] ?? null);
        $model = self::cleanString($ifd0['Model'] ?? null);

        // Many cameras repeat the make inside the model ("Canon" + "Canon EOS R5").
        $camera = match (true) {
            $make && $model && Str::startsWith(Str::lower($model), Str::lower($make)) => $model,
            $make && $model => "{$make} {$model}",
            default => $model ?: $make,
        };

        $focal = self::rational($exif['FocalLength'] ?? null);
        $fNumber = self::rational($exif['FNumber'] ?? null);
        $iso = $exif['ISOSpeedRatings'] ?? $exif['PhotographicSensitivity'] ?? null;

        return [
            'camera' => $camera ?: null,
            'lens' => self::cleanString($exif['LensModel'] ?? $exif['UndefinedTag:0xA434'] ?? null),
            'focal_length' => $focal ? self::trimNumber($focal).' mm' : null,
            'aperture' => $fNumber ? 'f/'.self::trimNumber($fNumber) : null,
            'shutter_speed' => self::shutter($exif['ExposureTime'] ?? null),
            'iso' => is_array($iso) ? (int) reset($iso) : ($iso !== null ? (int) $iso : null),
            'taken_at' => self::dateTime($exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? $ifd0['DateTime'] ?? null),
        ];
    }

    /**
     * Intervention Image manager for the configured driver; metadata is stripped from all output.
     */
    public static function manager(): ImageManager
    {
        return config('media.driver') === 'imagick' && extension_loaded('imagick')
            ? ImageManager::imagick(strip: true)
            : ImageManager::gd(strip: true);
    }

    /**
     * Raise memory_limit for the duration of the request when the configured value is higher
     * (bitmaps of full-resolution photos do not fit into PHP's default 128M).
     */
    private static function ensureMemoryLimit(): void
    {
        $wanted = config('media.memory_limit');

        if (blank($wanted)) {
            return;
        }

        $current = ini_get('memory_limit');

        if ($current !== false && $current !== '-1' && self::toBytes($current) < self::toBytes($wanted)) {
            @ini_set('memory_limit', $wanted);
        }
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Absolute path to the source file: direct for local disks, temp copy otherwise (e.g. S3).
     *
     * @return array{0: string, 1: bool} [absolute path, is temporary copy]
     */
    private static function localCopy(Filesystem $storage, string $path): array
    {
        if ($storage instanceof FilesystemAdapter && $storage->getAdapter() instanceof LocalFilesystemAdapter) {
            return [$storage->path($path), false];
        }

        $temp = tempnam(sys_get_temp_dir(), 'photo-');
        file_put_contents($temp, $storage->readStream($path));

        return [$temp, true];
    }

    private static function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');

        return $value === '' ? null : Str::limit($value, 255, '');
    }

    /**
     * "28/10" -> 2.8, "50/1" -> 50.0, "2.8" -> 2.8
     */
    private static function rational(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (float) $value : null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_contains($value, '/')) {
            [$num, $den] = array_map('floatval', explode('/', $value, 2));

            return $den > 0 && $num > 0 ? $num / $den : null;
        }

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private static function trimNumber(float $value, int $decimals = 1): string
    {
        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    }

    /**
     * Exposure time as photographers read it: "1/250", "1/2", "2", "30".
     */
    private static function shutter(mixed $value): ?string
    {
        $seconds = self::rational($value);

        if ($seconds === null) {
            return null;
        }

        return $seconds >= 1
            ? self::trimNumber($seconds)
            : '1/'.round(1 / $seconds);
    }

    private static function dateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/', $value) || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y:m:d H:i:s', $value);
        } catch (Throwable) {
            return null;
        }
    }
}
