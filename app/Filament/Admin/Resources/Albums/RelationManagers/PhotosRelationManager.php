<?php

namespace App\Filament\Admin\Resources\Albums\RelationManagers;

use App\Filament\Admin\Resources\Albums\Schemas\PhotoForm;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Tag;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Photos';

    public function form(Schema $schema): Schema
    {
        /** @var Album $album */
        $album = $this->getOwnerRecord();

        return $schema->components(PhotoForm::components($album));
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('tags'))
            ->recordTitle(fn (Photo $record): string => $record->displayTitle())
            ->columns([
                ImageColumn::make('path_thumb')
                    ->label('')
                    ->disk(config('media.disk'))
                    ->square()
                    ->imageSize(64),
                TextColumn::make('title')
                    ->state(fn (Photo $record): string => $record->displayTitle())
                    ->description(fn (Photo $record): ?string => $record->caption)
                    ->searchable(['title', 'caption', 'original_filename'])
                    ->wrap(),
                ToggleColumn::make('is_cover')
                    ->label('Cover')
                    ->alignCenter(),
                TextColumn::make('tags.name')
                    ->label('Tags')
                    ->badge()
                    ->limitList(3)
                    ->toggleable(),
                TextColumn::make('dimensions')
                    ->state(fn (Photo $record): ?string => $record->width ? "{$record->width} × {$record->height}" : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('camera')
                    ->description(fn (Photo $record): ?string => $record->lens)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('taken_at')
                    ->label('Taken')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->alignCenter(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                $this->bulkUploadAction(),
                CreateAction::make()
                    ->label('Add photo')
                    ->slideOver(),
            ])
            ->recordActions([
                EditAction::make()->slideOver(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->tagBulkAction(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No photos yet')
            ->emptyStateDescription('Upload several photos at once with "Upload many", or add one with full details.');
    }

    /**
     * Uploads many files in one go; PhotoObserver processes each of them (WebP derivatives + EXIF).
     */
    protected function bulkUploadAction(): Action
    {
        return Action::make('bulkUpload')
            ->label('Upload many')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->modalHeading('Upload photos')
            ->modalDescription('Each file becomes a photo; titles and shooting data are read from the file name and EXIF. You can edit every photo afterwards.')
            ->modalSubmitActionLabel('Upload')
            ->schema([
                FileUpload::make('photos')
                    ->label('Photos')
                    ->multiple()
                    ->image()
                    ->reorderable()
                    ->appendFiles()
                    ->panelLayout('grid')
                    ->maxFiles(config('media.max_bulk_files'))
                    ->maxSize(config('media.max_upload_kb'))
                    ->disk(config('media.disk'))
                    ->directory(config('media.photos_dir'))
                    ->storeFileNamesIn('original_filenames')
                    ->required()
                    ->helperText('Up to '.config('media.max_bulk_files').' files, 64 MB each. Order of the files becomes the initial sort order.'),
                Select::make('tags')
                    ->label('Tags for all uploaded photos')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->options(fn () => Tag::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->action(function (array $data): void {
                /** @var Album $album */
                $album = $this->getOwnerRecord();
                $next = (int) $album->photos()->max('sort_order') + 1;
                // multiple() dehydrates a list of stored paths; storeFileNamesIn() a [path => original name] map.
                $names = $data['original_filenames'] ?? [];

                foreach (array_values($data['photos'] ?? []) as $index => $path) {
                    $photo = $album->photos()->create([
                        'path_large' => $path,
                        'original_filename' => $names[$path] ?? null,
                        'sort_order' => $next + $index,
                    ]);

                    if (! empty($data['tags'])) {
                        $photo->tags()->sync($data['tags']);
                    }
                }

                Notification::make()
                    ->title(count($data['photos'] ?? []).' photo(s) uploaded')
                    ->success()
                    ->send();
            });
    }

    protected function tagBulkAction(): BulkAction
    {
        return BulkAction::make('addTags')
            ->label('Add tags')
            ->icon(Heroicon::OutlinedTag)
            ->schema([
                Select::make('tags')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->required()
                    ->options(fn () => Tag::query()->orderBy('name')->pluck('name', 'id')),
            ])
            ->action(function (Collection $records, array $data): void {
                $records->each(fn (Photo $photo) => $photo->tags()->syncWithoutDetaching($data['tags']));
            })
            ->deselectRecordsAfterCompletion();
    }
}
