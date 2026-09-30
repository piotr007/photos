<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media disk
    |--------------------------------------------------------------------------
    |
    | Filesystem disk that stores the processed photo derivatives. Paths saved
    | in the database are relative to this disk's root, so switching to S3
    | later only requires changing the disk (not the data).
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Directories (relative to the disk root)
    |--------------------------------------------------------------------------
    */

    'photos_dir' => 'albums/photos',
    'inline_dir' => 'albums/inline',

    /*
    |--------------------------------------------------------------------------
    | Image driver: "gd" or "imagick"
    |--------------------------------------------------------------------------
    |
    | GD is always present in the Docker image (compiled with WebP). Imagick
    | gives better resampling and HEIC support when the extension is available.
    |
    */

    'driver' => env('MEDIA_DRIVER', 'gd'),

    /*
    |--------------------------------------------------------------------------
    | Derivative sizes (pixels) and WebP quality
    |--------------------------------------------------------------------------
    |
    | large  - lightbox on desktop, longest edge scaled down to `large_max`
    | medium - masonry grid / lightbox on mobile, longest edge <= `medium_max`
    | thumb  - square cover crop `thumb_size` x `thumb_size` for album tiles
    |
    */

    'large_max' => (int) env('MEDIA_LARGE_MAX', 2560),
    'medium_max' => (int) env('MEDIA_MEDIUM_MAX', 1600),
    'thumb_size' => (int) env('MEDIA_THUMB_SIZE', 600),

    'quality' => [
        'large' => 85,
        'medium' => 82,
        'thumb' => 80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload limits (KB) used by Filament FileUpload fields
    |--------------------------------------------------------------------------
    */

    'max_upload_kb' => 65536,
    'max_bulk_files' => 50,

    /*
    |--------------------------------------------------------------------------
    | Memory limit applied while decoding/resizing (GD needs ~4 bytes/pixel)
    |--------------------------------------------------------------------------
    |
    | A 24 Mpx JPEG decodes to ~100 MB; the default PHP limit of 128M would
    | fail. Set to null to leave php.ini untouched.
    |
    */

    'memory_limit' => env('MEDIA_MEMORY_LIMIT', '512M'),

];
