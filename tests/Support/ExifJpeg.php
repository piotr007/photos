<?php

namespace Tests\Support;

/**
 * Builds small JPEG files with a real EXIF (APP1) segment for pipeline tests -
 * no external fixtures or tools required.
 */
final class ExifJpeg
{
    private const ASCII = 2;

    private const SHORT = 3;

    private const LONG = 4;

    private const RATIONAL = 5;

    /**
     * @param  array<string, mixed>  $tags  Supported keys: Make, Model, Orientation, ExposureTime ([num, den]),
     *                                      FNumber ([num, den]), ISOSpeedRatings, DateTimeOriginal, FocalLength ([num, den]), LensModel
     */
    public static function make(int $width, int $height, array $tags = []): string
    {
        $image = imagecreatetruecolor($width, $height);
        // Two-tone image so that orientation changes are visible in pixel data if ever needed.
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, imagecolorallocate($image, 200, 60, 60));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width, $height, imagecolorallocate($image, 60, 60, 200));

        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        if ($tags === []) {
            return $jpeg;
        }

        $app1 = "Exif\0\0".self::tiff($tags);
        $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        // Insert APP1 right after SOI (FF D8).
        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private static function tiff(array $tags): string
    {
        $ifd0 = [];
        $exif = [];

        if (isset($tags['Make'])) {
            $ifd0[0x010F] = [self::ASCII, $tags['Make']."\0"];
        }
        if (isset($tags['Model'])) {
            $ifd0[0x0110] = [self::ASCII, $tags['Model']."\0"];
        }
        if (isset($tags['Orientation'])) {
            $ifd0[0x0112] = [self::SHORT, [$tags['Orientation']]];
        }
        if (isset($tags['ExposureTime'])) {
            $exif[0x829A] = [self::RATIONAL, [$tags['ExposureTime']]];
        }
        if (isset($tags['FNumber'])) {
            $exif[0x829D] = [self::RATIONAL, [$tags['FNumber']]];
        }
        if (isset($tags['ISOSpeedRatings'])) {
            $exif[0x8827] = [self::SHORT, [$tags['ISOSpeedRatings']]];
        }
        if (isset($tags['DateTimeOriginal'])) {
            $exif[0x9003] = [self::ASCII, $tags['DateTimeOriginal']."\0"];
        }
        if (isset($tags['FocalLength'])) {
            $exif[0x920A] = [self::RATIONAL, [$tags['FocalLength']]];
        }
        if (isset($tags['LensModel'])) {
            $exif[0xA434] = [self::ASCII, $tags['LensModel']."\0"];
        }

        // ExifIFDPointer placeholder; the offset is patched once the layout is known.
        if ($exif !== []) {
            $ifd0[0x8769] = [self::LONG, [0]];
        }

        ksort($ifd0);
        ksort($exif);

        $ifd0Offset = 8;
        $ifd0Size = 2 + count($ifd0) * 12 + 4;
        $exifOffset = $ifd0Offset + $ifd0Size;
        $exifSize = $exif === [] ? 0 : 2 + count($exif) * 12 + 4;
        $dataOffset = $exifOffset + $exifSize;

        if ($exif !== []) {
            $ifd0[0x8769] = [self::LONG, [$exifOffset]];
        }

        $data = '';
        $ifd0Bytes = self::ifd($ifd0, $dataOffset, $data);
        $exifBytes = $exif === [] ? '' : self::ifd($exif, $dataOffset, $data);

        return 'II'.pack('v', 42).pack('V', $ifd0Offset).$ifd0Bytes.$exifBytes.$data;
    }

    /**
     * @param  array<int, array{0: int, 1: mixed}>  $entries
     */
    private static function ifd(array $entries, int &$dataOffset, string &$data): string
    {
        $out = pack('v', count($entries));

        foreach ($entries as $tag => [$type, $value]) {
            [$count, $bytes] = self::encode($type, $value);
            $out .= pack('v', $tag).pack('v', $type).pack('V', $count);

            if (strlen($bytes) <= 4) {
                $out .= str_pad($bytes, 4, "\0");
            } else {
                $out .= pack('V', $dataOffset);
                $data .= $bytes;
                $dataOffset += strlen($bytes);
            }
        }

        return $out.pack('V', 0);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function encode(int $type, mixed $value): array
    {
        return match ($type) {
            self::ASCII => [strlen($value), $value],
            self::SHORT => [count($value), implode('', array_map(fn (int $v) => pack('v', $v), $value))],
            self::LONG => [count($value), implode('', array_map(fn (int $v) => pack('V', $v), $value))],
            self::RATIONAL => [count($value), implode('', array_map(fn (array $r) => pack('V', $r[0]).pack('V', $r[1]), $value))],
        };
    }
}
