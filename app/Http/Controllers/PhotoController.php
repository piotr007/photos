<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Photo;
use Illuminate\Contracts\View\View;

class PhotoController extends Controller
{
    public function show(Album $album, Photo $photo): View
    {
        abort_unless($album->is_published, 404);

        $photo->load('tags');

        // Neighbours in the album's curated order (sort_order, then id).
        $photos = $album->photos()->get(['id', 'slug', 'title', 'path_thumb', 'sort_order']);
        $index = $photos->search(fn (Photo $item) => $item->is($photo));

        return view('photos.show', [
            'album' => $album,
            'photo' => $photo,
            'previous' => $index > 0 ? $photos[$index - 1] : null,
            'next' => $index !== false && $index < $photos->count() - 1 ? $photos[$index + 1] : null,
            'position' => $index === false ? null : $index + 1,
            'total' => $photos->count(),
        ]);
    }
}
