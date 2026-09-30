<?php

namespace App\Filament\Admin\Resources\Albums\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AlbumForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Album')
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            // Auto-fill the slug from the title while creating; on edit the slug is kept stable (public URL).
                            ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('Public URL: /galleries/{slug}'),
                        Textarea::make('short_description')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Shown on the gallery tiles and as the meta description.')
                            ->columnSpanFull(),
                        DatePicker::make('shot_at')
                            ->label('Shot on'),
                        TextInput::make('location')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Publishing')
                    ->schema([
                        Toggle::make('is_published')
                            ->label('Published')
                            ->live()
                            ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                                if ($state && blank($get('published_at'))) {
                                    $set('published_at', now()->format('Y-m-d H:i:s'));
                                }
                            }),
                        Toggle::make('is_featured')
                            ->label('Featured on home page'),
                        DateTimePicker::make('published_at')
                            ->seconds(false)
                            ->visible(fn (Get $get): bool => (bool) $get('is_published'))
                            ->helperText('Used for ordering when sort order is equal.'),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Lower numbers come first. You can also drag rows in the list.'),
                    ])
                    ->columns(2),

                Section::make('Description')
                    ->schema([
                        RichEditor::make('description')
                            ->hiddenLabel()
                            ->fileAttachmentsDisk(config('media.disk'))
                            ->fileAttachmentsDirectory(config('media.inline_dir'))
                            ->fileAttachmentsVisibility('public'),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                Section::make('Technical notes')
                    ->description('Gear, technique, processing - shown at the bottom of the gallery page.')
                    ->schema([
                        RichEditor::make('technical_notes')
                            ->hiddenLabel(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }
}
