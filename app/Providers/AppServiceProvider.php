<?php

namespace App\Providers;

use App\Models\Photo;
use App\Observers\PhotoObserver;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Photo::observe(PhotoObserver::class);

        // Main navigation with the active state resolved by route name (not by URL prefix).
        View::composer('layouts.app', function (ViewInstance $view): void {
            $view->with('navigation', [
                ['label' => 'Home', 'url' => route('home'), 'active' => request()->routeIs('home')],
                ['label' => 'Galleries', 'url' => route('albums.index'), 'active' => request()->routeIs('albums.*', 'photos.*', 'tags.*')],
                ['label' => 'About', 'url' => route('about'), 'active' => request()->routeIs('about')],
            ]);
        });
    }
}
