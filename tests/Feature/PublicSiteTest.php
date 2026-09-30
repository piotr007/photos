<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Photo;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_with_featured_galleries_and_hero(): void
    {
        $featured = Album::factory()->featured()->create(['title' => 'Northern Light']);
        $cover = Photo::factory()->cover()->create(['album_id' => $featured->id, 'title' => 'Aurora']);
        Album::factory()->create(['title' => 'Not Featured']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Northern Light')
            ->assertDontSee('Not Featured')
            ->assertSee($cover->url('large'), false)
            ->assertSee('og:image', false);
    }

    public function test_home_page_falls_back_to_latest_galleries_when_nothing_is_featured(): void
    {
        Album::factory()->create(['title' => 'Plain Gallery']);

        $this->get('/')->assertOk()->assertSee('Plain Gallery');
    }

    public function test_home_page_works_with_an_empty_database(): void
    {
        $this->get('/')->assertOk()->assertSee('No galleries published yet');
    }

    public function test_galleries_index_lists_only_published_albums_in_curated_order(): void
    {
        Album::factory()->create(['title' => 'Second', 'sort_order' => 2]);
        Album::factory()->create(['title' => 'First', 'sort_order' => 1]);
        Album::factory()->unpublished()->create(['title' => 'Draft Album']);

        $this->get('/galleries')
            ->assertOk()
            ->assertSeeInOrder(['First', 'Second'])
            ->assertDontSee('Draft Album')
            ->assertSee('2 galleries');
    }

    public function test_gallery_tile_uses_the_cover_photo_and_falls_back_to_the_first_photo(): void
    {
        $withCover = Album::factory()->create();
        Photo::factory()->create(['album_id' => $withCover->id, 'sort_order' => 1, 'path_thumb' => 'albums/photos/first-thumb.webp']);
        Photo::factory()->cover()->create(['album_id' => $withCover->id, 'sort_order' => 2, 'path_thumb' => 'albums/photos/cover-thumb.webp']);

        $withoutCover = Album::factory()->create();
        Photo::factory()->create(['album_id' => $withoutCover->id, 'sort_order' => 1, 'path_thumb' => 'albums/photos/fallback-thumb.webp']);

        $this->get('/galleries')
            ->assertOk()
            ->assertSee('cover-thumb.webp')
            ->assertDontSee('first-thumb.webp')
            ->assertSee('fallback-thumb.webp');
    }

    public function test_album_page_shows_photos_with_lightbox_data_and_details_links(): void
    {
        $album = Album::factory()->create(['slug' => 'dolomites', 'description' => '<p>Rich <em>text</em></p>', 'technical_notes' => '<p>Tripod</p>']);
        $photo = Photo::factory()->create(['album_id' => $album->id, 'title' => 'Tre Cime', 'caption' => 'Sunrise & fog', 'slug' => 'tre-cime']);

        $this->get('/galleries/dolomites')
            ->assertOk()
            ->assertSee('Tre Cime')
            ->assertSee('Rich <em>text</em>', false)
            ->assertSee('Tripod')
            ->assertSee('data-gallery="album-'.$album->id.'"', false)
            ->assertSee('data-description=".photo-desc-'.$photo->id.'"', false)
            ->assertSee('Sunrise &amp; fog', false)
            ->assertSee($photo->exifLine())
            ->assertSee(route('photos.show', [$album, $photo]), false);
    }

    public function test_unpublished_or_missing_album_returns_404(): void
    {
        Album::factory()->unpublished()->create(['slug' => 'draft']);

        $this->get('/galleries/draft')->assertNotFound()->assertSee('not in the frame');
        $this->get('/galleries/does-not-exist')->assertNotFound();
    }

    public function test_photo_page_shows_all_details_tags_and_neighbours(): void
    {
        $album = Album::factory()->create(['slug' => 'street']);
        $tag = Tag::factory()->create(['name' => 'Night', 'slug' => 'night']);
        $first = Photo::factory()->create(['album_id' => $album->id, 'slug' => 'one', 'title' => 'One', 'sort_order' => 1]);
        $second = Photo::factory()->create([
            'album_id' => $album->id,
            'slug' => 'two',
            'title' => 'Two',
            'sort_order' => 2,
            'caption' => 'Neon rain',
            'description' => '<p>The story.</p>',
            'location' => 'Tokyo',
            'camera' => 'Ricoh GR III',
            'lens' => null,
            'focal_length' => '28 mm',
            'aperture' => 'f/2.8',
            'shutter_speed' => '1/30',
            'iso' => 3200,
        ]);
        $second->tags()->attach($tag);
        $third = Photo::factory()->create(['album_id' => $album->id, 'slug' => 'three', 'title' => 'Three', 'sort_order' => 3]);

        $this->get('/galleries/street/two')
            ->assertOk()
            ->assertSee('Two')
            ->assertSee('Neon rain')
            ->assertSee('The story.')
            ->assertSee('Tokyo')
            ->assertSee('Ricoh GR III')
            ->assertSee('28 mm')
            ->assertSee('f/2.8')
            ->assertSee('1/30 s')
            ->assertSee('3200')
            ->assertSee('#Night')
            ->assertSee(route('tags.show', $tag), false)
            ->assertSee('2 / 3')
            ->assertSee(route('photos.show', [$album, $first]), false)
            ->assertSee(route('photos.show', [$album, $third]), false)
            ->assertSee('og:image', false);
    }

    public function test_photo_slug_is_scoped_to_its_album(): void
    {
        $album = Album::factory()->create(['slug' => 'a']);
        $other = Album::factory()->create(['slug' => 'b']);
        Photo::factory()->create(['album_id' => $album->id, 'slug' => 'shot']);

        $this->get('/galleries/a/shot')->assertOk();
        $this->get('/galleries/b/shot')->assertNotFound();
        $this->get('/galleries/a/missing')->assertNotFound();
    }

    public function test_photo_of_an_unpublished_album_is_not_public(): void
    {
        $album = Album::factory()->unpublished()->create(['slug' => 'draft']);
        Photo::factory()->create(['album_id' => $album->id, 'slug' => 'shot']);

        $this->get('/galleries/draft/shot')->assertNotFound();
    }

    public function test_tag_page_lists_only_photos_from_published_albums(): void
    {
        $tag = Tag::factory()->create(['slug' => 'portrait', 'name' => 'Portrait']);
        $public = Photo::factory()->create(['title' => 'Visible Portrait']);
        $hidden = Photo::factory()->create(['title' => 'Hidden Portrait', 'album_id' => Album::factory()->unpublished()]);
        $tag->photos()->attach([$public->id, $hidden->id]);

        $this->get('/tags/portrait')
            ->assertOk()
            ->assertSee('#Portrait')
            ->assertSee('Visible Portrait')
            ->assertDontSee('Hidden Portrait')
            ->assertSee('1 photo');

        $this->get('/tags/unknown')->assertNotFound();
    }

    public function test_about_page_renders_and_navigation_marks_the_active_item(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('About')
            ->assertSee('aria-current="page"', false);
    }
}
