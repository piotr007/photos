<?php

namespace App\Models;

use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    protected $fillable = [
        'album_id',
        'slug',
        'title',
        'caption',
        'description',
        'alt',
        'path_large',
        'path_medium',
        'path_thumb',
        'original_filename',
        'width',
        'height',
        'is_cover',
        'sort_order',
        'taken_at',
        'location',
        'camera',
        'lens',
        'focal_length',
        'aperture',
        'shutter_speed',
        'iso',
    ];

    protected $casts = [
        'is_cover' => 'boolean',
        'taken_at' => 'datetime',
        'width' => 'integer',
        'height' => 'integer',
        'iso' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Photo $photo): void {
            if (blank($photo->slug)) {
                $photo->slug = $photo->generateUniqueSlug();
            }
        });
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    /**
     * Photos that belong to a published album (public visibility).
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereHas('album', fn (Builder $album) => $album->where('is_published', true));
    }

    /**
     * Public URL of a derivative: large | medium | thumb.
     */
    public function url(string $size = 'large'): string
    {
        return Storage::disk(config('media.disk'))->url($this->{"path_{$size}"});
    }

    public function aspectRatio(): ?float
    {
        return ($this->width && $this->height) ? $this->width / $this->height : null;
    }

    public function displayTitle(): string
    {
        if (filled($this->title)) {
            return $this->title;
        }

        if (filled($this->original_filename)) {
            return pathinfo($this->original_filename, PATHINFO_FILENAME);
        }

        return 'Untitled';
    }

    /**
     * Human-readable shooting data for the lightbox and the photo page.
     *
     * @return array<string, string>
     */
    public function exifSummary(): array
    {
        return array_filter([
            'Camera' => $this->camera,
            'Lens' => $this->lens,
            'Focal length' => $this->focal_length,
            'Aperture' => $this->aperture,
            'Shutter speed' => $this->shutter_speed ? $this->shutter_speed.' s' : null,
            'ISO' => $this->iso ? (string) $this->iso : null,
        ], fn (?string $value) => filled($value));
    }

    /**
     * Single-line "camera · lens · 35 mm · f/2.8 · 1/250 s · ISO 100" used under the lightbox caption.
     */
    public function exifLine(): ?string
    {
        $parts = array_filter([
            $this->camera,
            $this->lens,
            $this->focal_length,
            $this->aperture,
            $this->shutter_speed ? $this->shutter_speed.' s' : null,
            $this->iso ? 'ISO '.$this->iso : null,
        ], fn (?string $value) => filled($value));

        return $parts ? implode(' · ', $parts) : null;
    }

    /**
     * Slug from the title (or original file name), made unique within the album.
     */
    protected function generateUniqueSlug(): string
    {
        $source = filled($this->title)
            ? $this->title
            : (filled($this->original_filename) ? pathinfo($this->original_filename, PATHINFO_FILENAME) : 'photo');

        $base = Str::slug($source) ?: 'photo';
        $slug = $base;
        $i = 2;

        while (static::query()
            ->where('album_id', $this->album_id)
            ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->getKey()))
            ->where('slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
