<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Contracts\View\View;

class TagController extends Controller
{
    public function show(Tag $tag): View
    {
        $photos = $tag->photos()
            ->published()
            ->with('album')
            ->latest('taken_at')
            ->latest('id')
            ->paginate(36);

        return view('tags.show', compact('tag', 'photos'));
    }
}
