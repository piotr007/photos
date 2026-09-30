<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\ExifJpeg;
use Tests\TestCase;

/**
 * Covers the media pipeline driven by PhotoObserver: raw upload -> 3 WebP derivatives,
 * EXIF extraction, file cleanup on replace/delete and the single-cover rule.
 */
class PhotoPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const EXIF = [
        'Make' => 'Fujifilm',
        'Model' => 'X-T5',
        'ExposureTime' => [1, 250],
        'FNumber' => [28, 10],
        'ISOSpeedRatings' => 800,
        'DateTimeOriginal' => '2026:05:17 14:32:10',
        'FocalLength' => [23, 1],
        'LensModel' => 'XF23mmF1.4 R',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('media.disk', 'public');
    }

    public function test_creating_a_photo_from_a_raw_upload_generates_three_webp_derivatives_and_removes_the_original(): void
    {
        $album = Album::factory()->create();
        $raw = $this->putRaw(ExifJpeg::make(3000, 2000, self::EXIF));

        $photo = $album->photos()->create(['path_large' => $raw, 'original_filename' => 'IMG_0001.jpg']);

        Storage::disk('public')->assertMissing($raw);

        foreach (['large' => [2560, 1707], 'medium' => [1600, 1067], 'thumb' => [600, 600]] as $size => [$width, $height]) {
            $path = $photo->{"path_{$size}"};

            $this->assertStringEndsWith("-{$size}.webp", $path);
            Storage::disk('public')->assertExists($path);

            $info = getimagesize(Storage::disk('public')->path($path));
            $this->assertSame('image/webp', $info['mime']);
            $this->assertSame([$width, $height], [$info[0], $info[1]], "Unexpected dimensions for {$size}");
        }

        $this->assertSame(2560, $photo->width);
        $this->assertSame(1707, $photo->height);
    }

    public function test_exif_data_is_extracted_into_the_photo_columns(): void
    {
        $album = Album::factory()->create();

        $photo = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(400, 300, self::EXIF))]);

        $this->assertSame('Fujifilm X-T5', $photo->camera);
        $this->assertSame('XF23mmF1.4 R', $photo->lens);
        $this->assertSame('23 mm', $photo->focal_length);
        $this->assertSame('f/2.8', $photo->aperture);
        $this->assertSame('1/250', $photo->shutter_speed);
        $this->assertSame(800, $photo->iso);
        $this->assertSame('2026-05-17 14:32:10', $photo->taken_at->format('Y-m-d H:i:s'));
        $this->assertSame('Fujifilm X-T5 · XF23mmF1.4 R · 23 mm · f/2.8 · 1/250 s · ISO 800', $photo->exifLine());
    }

    public function test_exif_never_overrides_values_entered_by_the_admin(): void
    {
        $album = Album::factory()->create();

        $photo = $album->photos()->create([
            'path_large' => $this->putRaw(ExifJpeg::make(400, 300, self::EXIF)),
            'camera' => 'Leica M6',
            'iso' => 100,
        ]);

        $this->assertSame('Leica M6', $photo->camera);
        $this->assertSame(100, $photo->iso);
        $this->assertSame('XF23mmF1.4 R', $photo->lens, 'Empty fields are still filled from EXIF');
    }

    public function test_a_photo_without_exif_still_processes_and_leaves_shooting_data_empty(): void
    {
        $album = Album::factory()->create();

        $photo = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(500, 500))]);

        Storage::disk('public')->assertExists($photo->path_large);
        $this->assertNull($photo->camera);
        $this->assertNull($photo->taken_at);
        $this->assertSame([], $photo->exifSummary());
        $this->assertNull($photo->exifLine());
    }

    public function test_exif_orientation_is_applied_to_the_derivatives(): void
    {
        $album = Album::factory()->create();

        // 300x200 landscape file tagged "rotate 90° CW" must come out as a 200x300 portrait.
        $photo = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(300, 200, ['Orientation' => 6]))]);

        $this->assertSame([200, 300], [$photo->width, $photo->height]);
    }

    public function test_replacing_the_file_deletes_the_previous_derivatives(): void
    {
        $album = Album::factory()->create();
        $photo = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(400, 300))]);
        $old = [$photo->path_large, $photo->path_medium, $photo->path_thumb];

        $photo->update(['path_large' => $this->putRaw(ExifJpeg::make(600, 400), 'replacement.jpg')]);

        foreach ($old as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $photo->refresh();
        $this->assertNotContains($photo->path_large, $old);
        Storage::disk('public')->assertExists($photo->path_large);
        Storage::disk('public')->assertExists($photo->path_medium);
        Storage::disk('public')->assertExists($photo->path_thumb);
        $this->assertSame([600, 400], [$photo->width, $photo->height]);
    }

    public function test_updating_metadata_only_keeps_the_files_untouched(): void
    {
        $album = Album::factory()->create();
        $photo = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(400, 300))]);
        $paths = [$photo->path_large, $photo->path_medium, $photo->path_thumb];

        $photo->update(['title' => 'Renamed', 'caption' => 'New caption', 'sort_order' => 5]);

        $this->assertSame($paths, [$photo->fresh()->path_large, $photo->fresh()->path_medium, $photo->fresh()->path_thumb]);
        $this->assertCount(3, Storage::disk('public')->allFiles());
    }

    public function test_deleting_a_photo_removes_all_derivatives(): void
    {
        $album = Album::factory()->create();
        $photo = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(400, 300))]);

        $photo->delete();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_deleting_an_album_removes_the_files_of_all_its_photos(): void
    {
        $album = Album::factory()->create();
        $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(400, 300), 'a.jpg')]);
        $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(400, 300), 'b.jpg')]);
        $this->assertCount(6, Storage::disk('public')->allFiles());

        $album->delete();

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, Photo::count());
    }

    public function test_the_first_photo_becomes_the_cover_and_only_one_cover_exists_per_album(): void
    {
        $album = Album::factory()->create();

        $first = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(400, 300), 'a.jpg')]);
        $second = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(400, 300), 'b.jpg')]);

        $this->assertTrue($first->fresh()->is_cover);
        $this->assertFalse($second->fresh()->is_cover);

        $second->update(['is_cover' => true]);

        $this->assertFalse($first->fresh()->is_cover);
        $this->assertTrue($second->fresh()->is_cover);
        $this->assertTrue($album->fresh()->coverPhoto->is($second));
    }

    public function test_slugs_are_generated_from_the_title_or_file_name_and_are_unique_per_album(): void
    {
        $album = Album::factory()->create();
        $other = Album::factory()->create();

        $a = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(200, 200), 'a.jpg'), 'title' => 'Blue Hour']);
        $b = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(200, 200), 'b.jpg'), 'title' => 'Blue Hour']);
        $c = $album->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(200, 200), 'c.jpg'), 'original_filename' => 'DSCF0042.JPG']);
        $d = $other->photos()->create(['path_large' => $this->putRaw(ExifJpeg::make(200, 200), 'd.jpg'), 'title' => 'Blue Hour']);

        $this->assertSame('blue-hour', $a->slug);
        $this->assertSame('blue-hour-2', $b->slug);
        $this->assertSame('dscf0042', $c->slug);
        $this->assertSame('blue-hour', $d->slug, 'Same slug is allowed in a different album');
        $this->assertSame('DSCF0042', $c->displayTitle());
    }

    public function test_a_corrupt_upload_fails_without_leaving_files_or_a_record(): void
    {
        $album = Album::factory()->create();
        $raw = $this->putRaw('definitely not an image', 'broken.jpg');

        try {
            $album->photos()->create(['path_large' => $raw]);
            $this->fail('Expected the pipeline to reject a corrupt file.');
        } catch (\Throwable $exception) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $exception);
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, Photo::count());
    }

    private function putRaw(string $jpeg, string $name = 'raw.jpg'): string
    {
        $path = config('media.photos_dir').'/'.$name;
        Storage::disk('public')->put($path, $jpeg);

        return $path;
    }
}
