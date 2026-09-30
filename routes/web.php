<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/galleries', [AlbumController::class, 'index'])->name('albums.index');
Route::get('/galleries/{album:slug}', [AlbumController::class, 'show'])->name('albums.show');
// Scoped binding: the photo slug is resolved through $album->photos(), so slugs only need to be unique per album.
Route::get('/galleries/{album:slug}/{photo:slug}', [PhotoController::class, 'show'])
    ->scopeBindings()
    ->name('photos.show');

Route::get('/tags/{tag:slug}', [TagController::class, 'show'])->name('tags.show');

Route::view('/about', 'about')->name('about');
