<?php

namespace Database\Factories;

use App\Models\Album;
use App\Models\Photo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates Photo rows that already point at (fake) processed derivatives, so the
 * observer skips image processing. Use PhotoObserverTest for the real pipeline.
 *
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    protected $model = Photo::class;

    public function definition(): array
    {
        $base = config('media.photos_dir').'/'.Str::lower((string) Str::ulid());

        return [
            'album_id' => Album::factory(),
            'title' => Str::title(fake()->words(2, true)),
            'caption' => fake()->sentence(),
            'alt' => fake()->sentence(4),
            'path_large' => "{$base}-large.webp",
            'path_medium' => "{$base}-medium.webp",
            'path_thumb' => "{$base}-thumb.webp",
            'original_filename' => 'IMG_'.fake()->numberBetween(1000, 9999).'.jpg',
            'width' => 2560,
            'height' => 1707,
            'is_cover' => false,
            'sort_order' => 0,
            'taken_at' => fake()->dateTimeBetween('-2 years'),
            'location' => fake()->city(),
            'camera' => 'Canon EOS R5',
            'lens' => 'RF 50mm F1.2 L USM',
            'focal_length' => '50 mm',
            'aperture' => 'f/2.8',
            'shutter_speed' => '1/250',
            'iso' => 400,
        ];
    }

    public function cover(): static
    {
        return $this->state(fn () => ['is_cover' => true]);
    }
}
