<?php

namespace App\Filament\Admin\Resources\Albums\Pages;

use App\Filament\Admin\Resources\Albums\AlbumResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlbum extends CreateRecord
{
    protected static string $resource = AlbumResource::class;
}
