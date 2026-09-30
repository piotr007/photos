<?php

namespace App\Observers;

use App\Models\Photo;
use App\Support\PhotoMedia;

/**
 * Keeps the media pipeline in sync with the Photo model:
 * raw upload path -> processed derivatives, old files cleanup, single cover per album.
 */
class PhotoObserver
{
    /** EXIF-derived attributes that are only filled when the admin left them empty. */
    private const EXIF_FIELDS = ['camera', 'lens', 'focal_length', 'aperture', 'shutter_speed', 'iso', 'taken_at'];

    public function creating(Photo $photo): void
    {
        // Filament's FileUpload dehydrates the raw upload path into path_large;
        // path_thumb/path_medium are NOT NULL, so they must exist before INSERT.
        if (is_string($photo->path_large) && filled($photo->path_large) && blank($photo->path_thumb)) {
            $this->applyProcessed($photo, PhotoMedia::process($photo->path_large));
        }

        // First photo of an album becomes its cover automatically.
        if (! $photo->is_cover && ! Photo::query()->where('album_id', $photo->album_id)->exists()) {
            $photo->is_cover = true;
        }

        $this->ensureSingleCover($photo);
    }

    public function updating(Photo $photo): void
    {
        if ($photo->isDirty('path_large') && is_string($photo->path_large) && filled($photo->path_large)) {
            PhotoMedia::deletePaths([
                $photo->getOriginal('path_large'),
                $photo->getOriginal('path_medium'),
                $photo->getOriginal('path_thumb'),
            ]);

            $this->applyProcessed($photo, PhotoMedia::process($photo->path_large));
        }

        $this->ensureSingleCover($photo);
    }

    public function deleting(Photo $photo): void
    {
        PhotoMedia::delete($photo);
    }

    /**
     * @param  array<string, mixed>  $processed
     */
    private function applyProcessed(Photo $photo, array $processed): void
    {
        foreach (['path_large', 'path_medium', 'path_thumb', 'width', 'height'] as $attribute) {
            $photo->{$attribute} = $processed[$attribute];
        }

        foreach (self::EXIF_FIELDS as $field) {
            if (blank($photo->{$field}) && filled($processed[$field] ?? null)) {
                $photo->{$field} = $processed[$field];
            }
        }
    }

    private function ensureSingleCover(Photo $photo): void
    {
        if (! $photo->is_cover) {
            return;
        }

        // Mass update on purpose: bypasses events/timestamps of the other photos.
        Photo::query()
            ->where('album_id', $photo->album_id)
            ->when($photo->exists, fn ($query) => $query->whereKeyNot($photo->getKey()))
            ->update(['is_cover' => false]);
    }
}
