<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Photo;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $featured = Album::query()
            ->published()
            ->where('is_featured', true)
            ->with(['coverPhoto', 'photos'])
            ->curated()
            ->take(6)
            ->get();

        // Fallback: newest published galleries when nothing is flagged as featured.
        if ($featured->isEmpty()) {
            $featured = Album::query()
                ->published()
                ->with(['coverPhoto', 'photos'])
                ->curated()
                ->take(6)
                ->get();
        }

        $hero = $featured->first()?->coverOrFirst()
            ?? Photo::query()->published()->inRandomOrder()->first();

        return view('home', [
            'featured' => $featured,
            'hero' => $hero,
            'photoCount' => Photo::query()->published()->count(),
        ]);
    }
}
