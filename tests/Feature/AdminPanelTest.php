<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Admin\Resources\Albums\Pages\EditAlbum;
use App\Filament\Admin\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ExifJpeg;
use Tests\TestCase;

/**
 * Admin panel (Filament): resource pages render and the photo relation manager
 * lets the admin enter every piece of information about a photo.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('media.disk', 'public');

        $this->actingAs(User::factory()->create());
    }

    public function test_resource_pages_render(): void
    {
        $album = Album::factory()->create();
        Photo::factory()->count(2)->create(['album_id' => $album->id]);
        Tag::factory()->create();

        $this->get('/admin')->assertOk();
        $this->get('/admin/albums')->assertOk()->assertSee($album->title);
        $this->get('/admin/albums/create')->assertOk();
        $this->get("/admin/albums/{$album->id}/edit")->assertOk()->assertSee('Photos');
        $this->get('/admin/tags')->assertOk();
    }

    public function test_album_can_be_created_with_auto_published_at(): void
    {
        Livewire::test(CreateAlbum::class)
            ->fillForm([
                'title' => 'Winter Series',
                'slug' => 'winter-series',
                'short_description' => 'Cold light.',
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $album = Album::where('slug', 'winter-series')->firstOrFail();
        $this->assertTrue($album->is_published);
        $this->assertNotNull($album->published_at);
        $this->assertTrue($album->is_featured);
    }

    public function test_photo_metadata_can_be_edited_without_uploading_a_new_file(): void
    {
        $album = Album::factory()->create();
        $photo = Photo::factory()->create(['album_id' => $album->id, 'title' => 'Old', 'camera' => 'Old camera']);
        $tag = Tag::factory()->create();

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->callTableAction('edit', $photo, data: [
                'title' => 'Golden Hour',
                'slug' => 'golden-hour',
                'caption' => 'Last light on the ridge',
                'alt' => 'Mountain ridge in warm light',
                'description' => '<p>We waited two hours.</p>',
                'location' => 'Zakopane',
                'camera' => 'Nikon Z8',
                'lens' => 'Z 24-70mm f/2.8 S',
                'focal_length' => '70 mm',
                'aperture' => 'f/8',
                'shutter_speed' => '1/125',
                'iso' => 200,
                'taken_at' => '2026-03-01 17:45:00',
                'is_cover' => true,
                'sort_order' => 7,
                'tags' => [$tag->id],
            ])
            ->assertHasNoTableActionErrors();

        $photo->refresh();

        $this->assertSame('Golden Hour', $photo->title);
        $this->assertSame('golden-hour', $photo->slug);
        $this->assertSame('Last light on the ridge', $photo->caption);
        $this->assertSame('Mountain ridge in warm light', $photo->alt);
        $this->assertStringContainsString('We waited two hours.', $photo->description);
        $this->assertSame('Zakopane', $photo->location);
        $this->assertSame('Nikon Z8', $photo->camera);
        $this->assertSame('Z 24-70mm f/2.8 S', $photo->lens);
        $this->assertSame('70 mm', $photo->focal_length);
        $this->assertSame('f/8', $photo->aperture);
        $this->assertSame('1/125', $photo->shutter_speed);
        $this->assertSame(200, $photo->iso);
        $this->assertSame('2026-03-01 17:45', $photo->taken_at->format('Y-m-d H:i'));
        $this->assertTrue($photo->is_cover);
        $this->assertSame(7, $photo->sort_order);
        $this->assertTrue($photo->tags->contains($tag));
        // The upload field was left empty, so the existing files must be kept.
        $this->assertStringEndsWith('-large.webp', $photo->path_large);
    }

    public function test_photo_slug_must_be_unique_within_the_album(): void
    {
        $album = Album::factory()->create();
        Photo::factory()->create(['album_id' => $album->id, 'slug' => 'taken']);
        $photo = Photo::factory()->create(['album_id' => $album->id, 'slug' => 'free']);

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->callTableAction('edit', $photo, data: ['slug' => 'taken'])
            ->assertHasTableActionErrors(['slug']);
    }

    public function test_photo_can_be_created_from_an_uploaded_file_with_details(): void
    {
        $album = Album::factory()->create();
        $raw = config('media.photos_dir').'/upload.jpg';
        Storage::disk('public')->put($raw, ExifJpeg::make(800, 600, ['Make' => 'Sony', 'Model' => 'ILCE-7M4', 'ISOSpeedRatings' => 640]));

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->callTableAction('create', data: [
                'path_large' => [$raw],
                'title' => 'Harbour',
                'caption' => 'Early ferry',
            ])
            ->assertHasNoTableActionErrors();

        $photo = $album->photos()->firstOrFail();

        $this->assertSame('Harbour', $photo->title);
        $this->assertSame('harbour', $photo->slug);
        $this->assertSame('Early ferry', $photo->caption);
        $this->assertSame('Sony ILCE-7M4', $photo->camera, 'EXIF is read from the uploaded file');
        $this->assertSame(640, $photo->iso);
        $this->assertTrue($photo->is_cover, 'First photo becomes the cover');
        Storage::disk('public')->assertMissing($raw);
        Storage::disk('public')->assertExists($photo->path_large);
        Storage::disk('public')->assertExists($photo->path_medium);
        Storage::disk('public')->assertExists($photo->path_thumb);
    }

    public function test_many_photos_can_be_uploaded_at_once_with_shared_tags(): void
    {
        $album = Album::factory()->create();
        $tag = Tag::factory()->create();
        $paths = [];

        // Extension-less names: fillForm() would explode dotted keys of the original_filenames map into
        // nested arrays (a test-only artefact - in the real flow Filament sets the whole map in one request).
        foreach (['one', 'two', 'three'] as $name) {
            $paths[] = $path = config('media.photos_dir')."/{$name}";
            Storage::disk('public')->put($path, ExifJpeg::make(400, 300));
        }

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->callTableAction('bulkUpload', data: [
                'photos' => $paths,
                'original_filenames' => array_combine($paths, ['DSC_0001.jpg', 'DSC_0002.jpg', 'DSC_0003.jpg']),
                'tags' => [$tag->id],
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $photos = $album->photos()->get();

        $this->assertCount(3, $photos);
        $this->assertSame([1, 2, 3], $photos->pluck('sort_order')->all());
        $this->assertSame(['dsc-0001', 'dsc-0002', 'dsc-0003'], $photos->pluck('slug')->all());
        $this->assertSame(['DSC_0001.jpg', 'DSC_0002.jpg', 'DSC_0003.jpg'], $photos->pluck('original_filename')->all());
        $this->assertSame(1, $photos->where('is_cover', true)->count());
        $this->assertTrue($photos->every(fn (Photo $photo) => $photo->tags->contains($tag)));
        $this->assertCount(9, Storage::disk('public')->allFiles(), 'Three WebP derivatives per photo, no originals');
    }

    public function test_deleting_a_photo_from_the_table_removes_its_files(): void
    {
        $album = Album::factory()->create();
        $photo = $album->photos()->create([
            'path_large' => tap(config('media.photos_dir').'/raw.jpg', fn ($p) => Storage::disk('public')->put($p, ExifJpeg::make(300, 300))),
        ]);
        $this->assertCount(3, Storage::disk('public')->allFiles());

        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class])
            ->callTableAction('delete', $photo);

        $this->assertModelMissing($photo);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
