<?php

namespace Tests\Unit;

use App\Support\PhotoMedia;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExifJpeg;

class PhotoMediaExifTest extends TestCase
{
    public function test_exif_values_are_normalised_for_display(): void
    {
        $file = $this->write(ExifJpeg::make(64, 64, [
            'Make' => 'Canon',
            'Model' => 'Canon EOS R5',      // make repeated inside the model -> not duplicated
            'ExposureTime' => [1, 4000],
            'FNumber' => [4, 1],            // "f/4", not "f/4.0"
            'ISOSpeedRatings' => 100,
            'FocalLength' => [85, 1],
            'DateTimeOriginal' => '2025:12:24 08:15:00',
        ]));

        $exif = PhotoMedia::readExif($file);

        $this->assertSame('Canon EOS R5', $exif['camera']);
        $this->assertSame('1/4000', $exif['shutter_speed']);
        $this->assertSame('f/4', $exif['aperture']);
        $this->assertSame('85 mm', $exif['focal_length']);
        $this->assertSame(100, $exif['iso']);
        $this->assertSame('2025-12-24 08:15:00', $exif['taken_at']->format('Y-m-d H:i:s'));
        $this->assertNull($exif['lens']);
    }

    public function test_long_exposures_and_invalid_dates_are_handled(): void
    {
        $file = $this->write(ExifJpeg::make(64, 64, [
            'Make' => 'Nikon',
            'ExposureTime' => [30, 1],
            'FNumber' => [11, 1],
            'DateTimeOriginal' => '0000:00:00 00:00:00',
        ]));

        $exif = PhotoMedia::readExif($file);

        $this->assertSame('Nikon', $exif['camera']);
        $this->assertSame('30', $exif['shutter_speed']);
        $this->assertSame('f/11', $exif['aperture']);
        $this->assertNull($exif['taken_at']);
    }

    public function test_files_without_exif_return_nulls(): void
    {
        $exif = PhotoMedia::readExif($this->write(ExifJpeg::make(64, 64)));

        $this->assertSame([null, null, null, null, null, null, null], array_values($exif));
    }

    private function write(string $jpeg): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exif-').'.jpg';
        file_put_contents($path, $jpeg);
        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }
}
