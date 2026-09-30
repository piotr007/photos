<?php

namespace App\Models;

use Database\Factories\AlbumFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Album extends Model
{
    /** @use HasFactory<AlbumFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'title',
        'short_description',
        'description',
        'technical_notes',
        'shot_at',
        'location',
        'is_published',
        'is_featured',
        'published_at',
        'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'published_at' => 'datetime',
        'shot_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (Album $album): void {
            $album->slug = $album->slug ?: Str::slug($album->title);

            if ($album->is_published && ! $album->published_at) {
                $album->published_at = now();
            }
        });

        // Delete photos through Eloquent so PhotoObserver removes the files from disk
        // (the DB-level cascade alone would leave orphaned files in storage).
        static::deleting(function (Album $album): void {
            $album->photos()->get()->each->delete();
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Curated ordering: manual sort_order first, newest publication next.
     */
    public function scopeCurated(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('published_at')->orderByDesc('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class)->orderBy('sort_order')->orderBy('id');
    }

    public function coverPhoto(): HasOne
    {
        return $this->hasOne(Photo::class)->where('is_cover', true);
    }

    /**
     * Cover photo with fallback to the first photo. Eager load both relations
     * (`coverPhoto`, `photos`) when listing to avoid N+1 queries.
     */
    public function coverOrFirst(): ?Photo
    {
        return $this->coverPhoto ?? $this->photos->first();
    }
}
