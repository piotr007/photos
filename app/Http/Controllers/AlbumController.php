<?php

namespace App\Http\Controllers;

use App\Models\Album;
use Illuminate\Contracts\View\View;

class AlbumController extends Controller
{
    public function index(): View
    {
        $albums = Album::query()
            ->published()
            ->with(['coverPhoto', 'photos'])
            ->withCount('photos')
            ->curated()
            ->paginate(24);

        return view('albums.index', compact('albums'));
    }

    public function show(Album $album): View
    {
        abort_unless($album->is_published, 404);

        $album->load(['photos.tags']);

        return view('albums.show', compact('album'));
    }
}
