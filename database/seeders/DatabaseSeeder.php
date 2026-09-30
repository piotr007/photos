<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder (updateOrCreate everywhere): safe to run repeatedly.
 * Model events stay enabled on purpose so observers run if photos are ever seeded.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Dev-only credentials: never reset a production admin password from a seeder.
        if (app()->environment(['local', 'testing'])) {
            User::query()->updateOrCreate(
                ['email' => 'admin@photo.local'],
                ['name' => 'Admin', 'password' => 'password'],
            );
        }

        $album = Album::query()->updateOrCreate(
            ['slug' => 'sample-gallery'],
            [
                'title' => 'Sample Gallery',
                'short_description' => 'A starter gallery - replace it with your own work in the admin panel.',
                'description' => '<p>This sample gallery demonstrates the public gallery page, the masonry grid and the lightbox. Upload photos in <strong>/admin</strong> to see it come alive.</p>',
                'technical_notes' => '<p>Shot with whatever was at hand. Processed to WebP by the media pipeline.</p>',
                'location' => 'Somewhere nice',
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 0,
            ],
        );

        foreach (['Landscape', 'Portrait', 'Street', 'Black & White'] as $name) {
            Tag::query()->updateOrCreate(['name' => $name], []);
        }

        $this->command?->info("Seeded album '{$album->title}' and ".Tag::count().' tags.');
    }
}
