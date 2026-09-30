<?php

namespace App\Filament\Admin\Resources\Albums\Schemas;

use App\Models\Album;
use App\Models\Photo;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Validation\Rules\Unique;

/**
 * Photo form used by PhotosRelationManager. Every piece of information about
 * a photo (identity, captions, shooting data, tags, display) is editable here.
 */
class PhotoForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(Album $album): array
    {
        return [
            Section::make('Image')
                ->schema([
                    Placeholder::make('preview')
                        ->label('Current image')
                        ->content(fn (?Photo $record) => $record?->path_thumb
                            ? view('filament.photo-preview', ['photo' => $record])
                            : 'No image yet')
                        ->hiddenOn('create'),
                    FileUpload::make('path_large')
                        ->label(fn (string $operation): string => $operation === 'create' ? 'Photo file' : 'Replace photo')
                        ->image()
                        ->imagePreviewHeight('200')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->maxSize(config('media.max_upload_kb'))
                        ->disk(config('media.disk'))
                        ->directory(config('media.photos_dir'))
                        // Original file name is kept as metadata (Filament stores the file under a ULID name).
                        ->storeFileNamesIn('original_filename')
                        // Edit: start empty instead of loading the existing file into FilePond (avoids the endless spinner);
                        // the current image is shown by the Placeholder above.
                        ->formatStateUsing(fn () => [])
                        // Without a new file the field is excluded from the payload, so path_large is never overwritten with null.
                        ->dehydrated(fn ($state): bool => filled($state))
                        ->helperText('JPEG/PNG/WebP up to 64 MB. The upload is converted to three WebP sizes; the original is not kept. Empty shooting-data fields below are filled from EXIF.'),
                ])
                ->columnSpanFull(),

            Section::make('Details')
                ->schema([
                    TextInput::make('title')
                        ->maxLength(255),
                    TextInput::make('slug')
                        ->maxLength(255)
                        ->alphaDash()
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule) => $rule->where('album_id', $album->getKey()),
                        )
                        ->helperText('Public URL: /galleries/'.$album->slug.'/{slug}. Generated from the title when empty.')
                        ->hiddenOn('create'),
                    TextInput::make('caption')
                        ->maxLength(500)
                        ->helperText('Short text shown under the photo in the lightbox.')
                        ->columnSpanFull(),
                    TextInput::make('alt')
                        ->label('Alt text')
                        ->maxLength(255)
                        ->helperText('Describes the image for screen readers and search engines. Falls back to the title.')
                        ->columnSpanFull(),
                    RichEditor::make('description')
                        ->label('Story / description')
                        ->toolbarButtons([
                            ['bold', 'italic', 'underline', 'strike', 'link'],
                            ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'],
                            ['undo', 'redo'],
                        ])
                        ->helperText('Shown on the photo page.')
                        ->columnSpanFull(),
                    Select::make('tags')
                        ->relationship('tags', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->unique('tags', 'name'),
                        ])
                        ->helperText('Keywords / categories. Type to search or create a new one.')
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Shooting data')
                ->description('Filled automatically from EXIF when a file is uploaded and the field is empty. Edit freely.')
                ->schema([
                    DateTimePicker::make('taken_at')
                        ->label('Taken at')
                        ->seconds(false),
                    TextInput::make('location')
                        ->maxLength(255)
                        ->placeholder('Tatra Mountains, Poland'),
                    TextInput::make('camera')
                        ->maxLength(255)
                        ->placeholder('Canon EOS R5'),
                    TextInput::make('lens')
                        ->maxLength(255)
                        ->placeholder('RF 50mm F1.2 L USM'),
                    TextInput::make('focal_length')
                        ->maxLength(32)
                        ->placeholder('50 mm'),
                    TextInput::make('aperture')
                        ->maxLength(32)
                        ->placeholder('f/2.8'),
                    TextInput::make('shutter_speed')
                        ->maxLength(32)
                        ->placeholder('1/250'),
                    TextInput::make('iso')
                        ->label('ISO')
                        ->numeric()
                        ->minValue(1)
                        ->placeholder('100'),
                ])
                ->columns(4)
                ->collapsible(),

            Section::make('Display')
                ->schema([
                    Toggle::make('is_cover')
                        ->label('Album cover')
                        ->helperText('Only one photo per album can be the cover.'),
                    TextInput::make('sort_order')
                        ->numeric()
                        ->minValue(0)
                        ->default(fn (): int => (int) $album->photos()->max('sort_order') + 1)
                        ->helperText('You can also drag rows in the table.'),
                ])
                ->columns(2),
        ];
    }
}
