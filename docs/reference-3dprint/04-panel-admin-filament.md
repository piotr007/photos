# 04 — Panel administracyjny (Filament 5)

## 1. Konfiguracja panelu — `app/Providers/Filament/AdminPanelProvider.php`

```php
return $panel
    ->id('admin')->path('admin')
    ->login()                       // /admin/login (formularz Filamenta; brak własnych tras /login)
    ->passwordReset()               // /admin/password-reset/request + /admin/password-reset/reset
    ->profile()                     // /admin/profile — dane konta, hasło, sekcja MFA
    ->multiFactorAuthentication([
        AppAuthentication::make()->recoverable(),   // TOTP (Google Authenticator itp.) + kody odzyskiwania
        EmailAuthentication::make(),                // jednorazowe kody na e-mail
    ])                              // 3. parametr isRequired: false (opcjonalne per konto)
    ->colors(['primary' => Color::Amber])
    ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
    ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')      // katalog nie istnieje — OK
    ->pages([Dashboard::class])
    ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets') // katalog nie istnieje — OK
    ->widgets([AccountWidget::class, FilamentInfoWidget::class])
    ->middleware([EncryptCookies, AddQueuedCookiesToResponse, StartSession, AuthenticateSession,
                  ShareErrorsFromSession, PreventRequestForgery, SubstituteBindings,
                  DisableBladeIconComponents, DispatchServingFilamentEvent])
    ->authMiddleware([Authenticate::class]);
```

Rejestracja: `bootstrap/providers.php` → `App\Providers\Filament\AdminPanelProvider::class`
(dodane przez `php artisan filament:install --panels`).

Trasy generowane przez panel (istotne):

| URL | Co |
|---|---|
| `/admin` | dashboard (redirect do login dla gościa) |
| `/admin/login`, `/admin/logout` (POST) | logowanie |
| `/admin/password-reset/request`, `/admin/password-reset/reset?token=…&email=…` | reset hasła (mail przez Mailpit) |
| `/admin/profile` | edycja profilu + „Multi-factor authentication" (włączanie TOTP/e‑mail) |
| `/admin/multi-factor-authentication/…` | challenge 2FA po podaniu hasła |
| `/admin/projects`, `/admin/projects/create`, `/admin/projects/{id}/edit` | `ProjectResource` |
| `/livewire/upload-file`, `/livewire/update` | endpointy Livewire (upload plików!) |

## 2. Uwierzytelnianie i 2FA — jak działa

1. `User implements FilamentUser` — **wymagane poza `APP_ENV=local`** (inaczej 403 po zalogowaniu).
   `canAccessPanel()` zwraca `true` → każde konto w `users` to admin.
2. Reset hasła: standardowy broker `Password` Laravela + tabela `password_reset_tokens`; strony i
   maile dostarcza Filament. Powiadomienie jest `ShouldQueue` → `QUEUE_CONNECTION=sync` w dev.
3. MFA: użytkownik sam włącza w `/admin/profile`. Dane w `users`:
   `app_authentication_secret` (encrypted), `app_authentication_recovery_codes` (encrypted:array),
   `has_email_authentication` (bool). Kontrakty: `HasAppAuthentication`,
   `HasAppAuthenticationRecovery`, `HasEmailAuthentication` — implementacja to proste
   gettery/settery z `save()`.
4. Wymuszenie 2FA dla wszystkich: `->multiFactorAuthentication([...], isRequired: true)`.
5. Awaryjne wyłączenie 2FA: tinker → `User::where('email', ...)->update(['app_authentication_secret' => null, 'has_email_authentication' => false])`.
6. Tworzenie kont: `php artisan make:filament-user` (interaktywnie) / seeder / tinker.

## 3. `ProjectResource` — `app/Filament/Admin/Resources/ProjectResource.php`

```php
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';  // typed static (Filament 5)
    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema          // Filament\Schemas\Schema — NIE Forms\Form
    {
        return $schema->components([
            Section::make('Project details')->schema([           // Filament\Schemas\Components\Section
                TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('title')->required()->maxLength(255),
                Textarea::make('short_description')->required()->rows(3),
                Toggle::make('is_published')->default(false),
                DateTimePicker::make('published_at')->visible(fn (Get $get) => (bool) $get('is_published')),  // Filament\Schemas\Components\Utilities\Get
            ])->columns(2),
            Section::make('Description')->schema([Textarea::make('description')->required()->rows(14)])->columnSpanFull(),
            Section::make('Print notes')->schema([Textarea::make('print_notes')->rows(10)])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('slug')->searchable()->sortable(),
            IconColumn::make('is_published')->boolean(),
            TextColumn::make('published_at')->dateTime(),
            TextColumn::make('created_at')->dateTime()->since(),
        ])->defaultSort('created_at', 'desc');
        // brak filters(), brak recordActions() -> klik w wiersz = edycja (domyślne zachowanie), brak bulk actions
    }

    public static function getRelations(): array { return [ImagesRelationManager::class, FilesRelationManager::class]; }
    public static function getPages(): array { return [
        'index' => Pages\ListProjects::route('/'), 'create' => Pages\CreateProject::route('/create'), 'edit' => Pages\EditProject::route('/{record}/edit'),
    ]; }
}
```

Strony: `ListProjects` (header action `CreateAction`), `CreateProject` (pusta), `EditProject`
(header action `DeleteAction`). Relation managery wyświetlają się **tylko na stronie edycji**
(standard Filamenta — na create nie ma jeszcze rekordu nadrzędnego).

Uwagi:

- Toggle `is_published` nie ustawia `published_at` — pole trzeba wypełnić ręcznie (widoczne po
  włączeniu toggle). W klonie: `Toggle::make('is_published')->live()->afterStateUpdated(fn ($state, Set $set, Get $get) => $state && ! $get('published_at') ? $set('published_at', now()) : null)`.
- Slug ręczny — brak autogeneracji.
- Opisy to `Textarea` — publiczny widok renderuje `nl2br(e($text))`. Migracja na `RichEditor`
  wymaga zmiany renderowania na `{!! $html !!}` (treść pochodzi tylko od admina).

## 4. `ImagesRelationManager` — upload/zamiana zdjęcia, featured, kolejność

```php
protected static string $relationship = 'images';
protected static ?string $title = 'Images';

public function form(Schema $schema): Schema
{
    return $schema->components([
        Group::make([                                                    // Filament\Schemas\Components\Group
            Placeholder::make('current_thumb')->label('Current thumbnail')
                ->content(fn (?Get $get, ?ProjectImage $record) => $record?->path_thumb
                    ? view('filament.project-image-preview', [
                        'thumb' => '/storage/'.$record->path_thumb.'?v='.$record->updated_at?->timestamp,  // cache-buster po zamianie
                        'large' => '/storage/'.$record->path_large,
                        'alt'   => $record->alt,
                    ])
                    : 'No image'),
            FileUpload::make('path_large')->label('Upload new image')
                ->image()                                                // walidacja MIME image/*
                ->required(fn (string $operation): bool => $operation === 'create')
                ->maxSize(5120)                                          // KB (5 MB) — ale nginx 1 MB / PHP 2 MB blokują wcześniej!
                ->disk('public')->directory('projects/images')          // Filament sam zapisuje plik: projects/images/<ULID>.<ext>
                ->formatStateUsing(fn () => [])                          // na edit pole startuje puste (nie ładuje istniejącego pliku -> brak wiecznego spinnera)
                ->dehydrated(fn ($state): bool => filled($state))        // bez nowego pliku pole NIE trafia do $data -> brak "path_large cannot be null"
                ->helperText('Upload to replace the current image and regenerate large/thumb versions.'),
        ])->columnSpanFull(),
        Toggle::make('is_featured')->label('Featured image'),
        TextInput::make('alt')->maxLength(255),
        TextInput::make('sort_order')->numeric()->default(0),
    ])->columns(2);
}

public function table(Table $table): Table
{
    return $table
        ->headerActions([CreateAction::make()])                          // Filament\Actions\CreateAction
        ->recordActions([EditAction::make(), DeleteAction::make()])      // Filament 5: recordActions (nie ->actions())
        ->columns([TextColumn::make('alt')->limit(30), TextColumn::make('sort_order')->sortable()])
        ->defaultSort('sort_order')
        ->reorderable('sort_order');                                     // drag&drop zapisuje sort_order
}
```

Dlaczego tak (historia debugowania — **nie zmieniaj bez zrozumienia**):

| Element | Problem, który rozwiązuje |
|---|---|
| `formatStateUsing(fn () => [])` | domyślnie `FileUpload` na edycji ładuje istniejący plik do podglądu (FilePond) — przy plikach spoza domyślnego katalogu / z cache-busterem kończyło się nieskończonym spinnerem; pole służy **tylko do wgrania nowego** pliku |
| `dehydrated(fn ($state) => filled($state))` | gdy admin edytuje tylko `alt`/`sort_order`, puste pole uploadu nie nadpisuje `path_large` `null`‑em (`Integrity constraint violation: path_large cannot be null`) |
| `disk('public')->directory('projects/images')` | Filament zapisuje plik sam i dehydratuje **string** ścieżki (`projects/images/01J….jpg`) — ścieżka trafia do modelu jako zwykły string, więc mutator `setPathLargeAttribute` dostaje string, a observer `creating`/`updating` generuje thumb |
| `Placeholder` z własnym partialem | podgląd aktualnego zdjęcia niezależny od FilePond; `?v=timestamp` wymusza odświeżenie po zamianie |
| brak `->multiple()` | jeden rekord `ProjectImage` na formularz; bulk upload to zadanie na przyszłość (patrz `08-blueprint` §6) |
| brak kolumny z miniaturą w tabeli | do dodania: `ImageColumn::make('path_thumb')->disk('public')` |

Tabela **nie ma** kolumny `is_featured` ani miniatury — użytkownik widzi tylko `alt` i `sort_order`.

## 5. `FilesRelationManager` — pliki do pobrania

```php
protected static string $relationship = 'files';
form: TextInput label (required), Select file_type (stl|freecad|py|other, required),
      FileUpload::make('path')->required()->maxSize(20480)
          ->saveUploadedFileUsing(fn (UploadedFile $file) => ProjectMedia::storeFile($file)),  // MUSI zwracać string|null
      TextInput sort_order
table: headerActions CreateAction; recordActions Edit/Delete; columns label(searchable), file_type, sort_order; defaultSort + reorderable('sort_order')
mutateFormDataBeforeCreate(): konwersja UploadedFile->string — martwy kod (nigdy nie zachodzi)
```

- `saveUploadedFileUsing` zwracające tablicę → `storeFileName(): Argument #1 must be of type string`.
- `ProjectMedia::storeFile()` zapisuje jako `projects/files/<uuid>.<ext>` na dysku `public`
  (plik publicznie dostępny pod `/storage/projects/files/...`).
- Brak walidacji typu pliku (`acceptedFileTypes`) — dowolny plik do 20 MB (o ile nginx/PHP przepuszczą).
- Na edycji `FileUpload` bez `formatStateUsing([])` → pokazuje istniejący plik (dla nie‑obrazów
  FilePond nie ładuje podglądu, więc tu spinner nie występował).

## 6. Partial podglądu — `resources/views/filament/project-image-preview.blade.php`

```blade
<div class="space-y-2">
    <a href="{{ $large }}" target="_blank" class="inline-block">
        <img src="{{ $thumb }}" alt="{{ $alt ?? '' }}" class="h-32 rounded border object-cover">
    </a>
</div>
```

Względne URL‑e `/storage/...` (nie `asset()`), aby działały niezależnie od `APP_URL`.

## 7. Filament 5 — różnice względem przykładów z internetu (v3)

| Temat | Filament 5 (tu) | Stare (v3) |
|---|---|---|
| Sygnatura formularza | `public static function form(Schema $schema): Schema` (`Filament\Schemas\Schema`) | `form(Form $form): Form` |
| Layouty | `Filament\Schemas\Components\{Section,Group,Grid,Tabs,Fieldset}` | `Filament\Forms\Components\Section` |
| Utilities | `Filament\Schemas\Components\Utilities\{Get,Set}` | `Filament\Forms\Get` |
| Pola | nadal `Filament\Forms\Components\{TextInput,Textarea,Toggle,Select,FileUpload,DateTimePicker,Placeholder,RichEditor}` | jw. |
| Akcje | `Filament\Actions\{CreateAction,EditAction,DeleteAction,Action,BulkActionGroup,DeleteBulkAction}` — wspólne dla tabel i stron | `Filament\Tables\Actions\*` |
| Akcje wiersza | `$table->recordActions([...])`, bulk: `->toolbarActions([...])` | `->actions([...])`, `->bulkActions([...])` |
| Statics zasobu | `protected static string|\BackedEnum|null $navigationIcon`, `string|UnitEnum|null $navigationGroup` | `?string` |
| Auth/MFA | `Filament\Auth\MultiFactor\App\AppAuthentication`, `...\Email\EmailAuthentication`; kontrakty w `Filament\Auth\MultiFactor\*\Contracts` | brak wbudowanego MFA |
| Kolumny tabel | `Filament\Tables\Columns\{TextColumn,IconColumn,ImageColumn,ToggleColumn}` | jw. |
| Livewire | v4 | v3 |
| Generator | `php artisan make:filament-resource Album --generate` (tworzy form/table z kolumn DB), `make:filament-relation-manager AlbumResource photos caption` | |
| Upgrade | `composer.json` `post-autoload-dump` → `@php artisan filament:upgrade` (auto‑publikacja assetów) | |

Assety Filamenta są opublikowane do `public/{css,js,fonts}/filament/**` i **są w repo**
(nie w `.gitignore`) — po aktualizacji Filamenta `filament:upgrade` je odświeży.

## 8. Przepis: dodanie nowego zasobu w tym stylu

```bash
docker compose exec -u www-data app php artisan make:filament-resource Album --generate
docker compose exec -u www-data app php artisan make:filament-relation-manager AlbumResource photos caption
```

Następnie: popraw namespaces do `Filament\Schemas\*`, dodaj `Section`/`columns`, `reorderable`,
`recordActions`, a upload skonfiguruj wg §4 (kopiuj 1:1 z `ImagesRelationManager`).
