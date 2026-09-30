<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(1, Album::count());
        $this->assertSame(4, Tag::count());
        $this->assertTrue(Album::first()->is_published);
        $this->assertNotNull(Album::first()->published_at, 'published_at is set automatically');
        $this->assertSame('black-white', Tag::where('name', 'Black & White')->value('slug'));
    }
}
