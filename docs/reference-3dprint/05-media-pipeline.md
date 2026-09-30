# 05 — Pipeline mediów (zdjęcia i pliki) — przepływ krytyczny

> Ten obszar był najdłużej debugowany. Elementy zależą od siebie: konfiguracja `FileUpload`
> ⇄ mutator modelu ⇄ observer ⇄ helper `ProjectMedia` ⇄ widoki. Zmieniając jedno, sprawdź resztę.

## 1. Gdzie fizycznie są pliki

| Co | Dysk Laravel | Ścieżka fizyczna (kontener/host) | URL publiczny |
|---|---|---|---|
| zdjęcia large | `public` | `storage/app/public/projects/images/<ULID>.<ext>` | `/storage/projects/images/<ULID>.<ext>` |
| miniatury | `public` | `storage/app/public/projects/images/<ULID>-thumb.<ext>` | `/storage/projects/images/<ULID>-thumb.<ext>` |
| pliki do pobrania | `public` | `storage/app/public/projects/files/<uuid>.<ext>` | `/storage/projects/files/<uuid>.<ext>` |
| tymczasowe uploady Livewire | `local` (default) | `storage/app/private/livewire-tmp/` | pre‑signed URL Livewire |
| tmp PHP | — | `storage/tmp/` (`TMPDIR`, `sys_temp_dir`, `upload_tmp_dir`) | — |

Dysk `public` (`config/filesystems.php`): `root => storage_path('app/public')`,
`url => APP_URL.'/storage'`, `visibility => public`, `throw => false` (błędy zapisu **nie rzucają
wyjątków** — `put()` zwraca `false`; miej to na uwadze przy debugowaniu).
Symlink `public/storage → ../storage/app/public` tworzy `php artisan storage:link` (w `.gitignore`).

W bazie zapisywana jest ścieżka **względem roota dysku** (`projects/images/…`). Widoki budują URL
przez `asset('storage/' . $path)`, panel przez `'/storage/' . $path`.

## 2. Przepływ: dodanie zdjęcia (Create w `ImagesRelationManager`)

```mermaid
sequenceDiagram
    participant A as Admin (przeglądarka)
    participant LW as Livewire (/livewire/upload-file)
    participant F as Filament FileUpload
    participant M as ProjectImage (Eloquent)
    participant O as ProjectImageObserver
    participant PM as ProjectMedia
    participant S as Storage disk public

    A->>LW: wybór pliku -> upload tymczasowy (limit nginx 1MB / PHP 2MB / Livewire 12MB / pole 5MB)
    LW-->>A: TemporaryUploadedFile (storage/app/private/livewire-tmp)
    A->>F: Submit formularza (alt, is_featured, sort_order, path_large=temp)
    F->>S: saveUploadedFile: putFileAs('projects/images', <ULID>.<ext>)
    F->>M: create(['path_large' => 'projects/images/<ULID>.<ext>', 'alt', 'is_featured', 'sort_order', 'project_id'])
    M->>M: setPathLargeAttribute(string) -> attributes['path_large'] = string
    M->>O: creating()
    O->>PM: makeThumb(path_large)  (gdy path_thumb puste)
    PM->>S: delete(<ULID>-thumb.<ext>); copy(<ULID>.<ext> -> <ULID>-thumb.<ext>)
    PM-->>O: 'projects/images/<ULID>-thumb.<ext>'
    O->>M: path_thumb = ...
    Note over O: jeśli is_featured: UPDATE project_images SET is_featured=0 WHERE project_id=X (query builder, bez eventów)
    M->>M: INSERT
```

Kluczowe: **Filament sam zapisuje plik** (bo `->disk('public')->directory('projects/images')`)
i przekazuje **string**. Nazwa: `Str::ulid() . '.' . oryginalne_rozszerzenie` (domyślne
`getUploadedFileNameForStorageUsing` w `BaseFileUpload::setUp()`; `preserveFilenames()` zmieniłoby
to na nazwę oryginalną — nie używać, kolizje).

## 3. Przepływ: zamiana zdjęcia (Edit + nowy plik)

1. `FileUpload` ma `formatStateUsing(fn () => [])` → pole puste na starcie; podgląd aktualnego
   zdjęcia daje `Placeholder` z partialem (`?v=<updated_at timestamp>` jako cache‑buster).
2. Admin wgrywa nowy plik → Filament zapisuje `projects/images/<nowyULID>.<ext>` i podaje string.
3. `ProjectImageObserver::updating()`: `isDirty('path_large')` → `Storage::disk('public')->delete([stary large, stary thumb])`
   → `path_thumb = ProjectMedia::makeThumb(nowy large)`.
4. Jeśli admin **nie** wgrał pliku → `dehydrated(fn ($state) => filled($state))` wyklucza pole z
   `$data` → `path_large` nietknięte → observer nie kasuje nic.

## 4. Przepływ: usunięcie, kolejność, featured

- **Delete** (`DeleteAction` w tabeli RM) → `ProjectImage::delete()` → `deleting()` →
  `Storage::disk('public')->delete([path_large, path_thumb])`.
- **Reorder** (drag & drop) → Filament masowo aktualizuje `sort_order` (`Model::update`) →
  `updating()` odpala się, ale `path_large` nie jest dirty i `is_featured` bez zmian → brak efektów.
- **Featured**: w `creating`/`updating`, gdy `is_featured` truthy → masowy `update(['is_featured' => false])`
  na pozostałych obrazach projektu (query builder, bez eventów). Gwarancja: ≤ 1 featured na projekt.
  Nie ma gwarancji, że **jest** jakiś featured → lista publiczna robi fallback na `images->first()`.
- **Usunięcie projektu** → `cascadeOnDelete` w DB → rekordy obrazów/plików znikają **bez observerów**
  → **pliki osierocone** na dysku. Fix (zalecany w klonie):
  ```php
  // Project::booted()
  static::deleting(fn (Project $p) => $p->images->each->delete() && $p->files->each->delete());
  ```

## 5. `App\Support\ProjectMedia` — co naprawdę robi

| Metoda | Wejście → wyjście | Realne zachowanie | Kto używa |
|---|---|---|---|
| `storeImage(UploadedFile $file): array` | plik → `['path_large' => 'projects/images/<uuid>-large.webp', 'path_thumb' => '…-thumb.webp']` | `putFileAs()` **bez konwersji** (bajty oryginału pod nazwą `.webp`!) + `copy()` na thumb | tylko mutator `ProjectImage::setPathLargeAttribute()` gdy dostanie `UploadedFile` (nie w flow Filamenta) |
| `makeThumb(string $largePath): string` | `projects/images/X.jpg` → `projects/images/X-thumb.jpg` | regex wstawia `-thumb` przed rozszerzeniem (fallback: dopisuje `-thumb` na końcu, gdy brak rozszerzenia); `delete(thumb)` + `copy(large → thumb)`; **brak resize** | observer `creating`/`updating` |
| `storeFile(UploadedFile $file): string` | plik → `projects/files/<uuid>.<ext>` | `putFileAs()`; rozszerzenie z `getClientOriginalExtension()` (może być puste) | `FilesRelationManager::saveUploadedFileUsing` |

Konsekwencja: **thumb = identyczna kopia large**. Publiczna lista i galeria ładują pełne pliki
(wolno przy dużych zdjęciach). Docelowo (plan §7): large max 1200×900 bez rozciągania, thumb
300×300 crop 1:1, oba WebP, bez oryginału.

## 6. Limity uploadu — stan faktyczny (zweryfikowany statycznie)

| Warstwa | Ustawienie | Wartość obecna | Skutek przekroczenia |
|---|---|---|---|
| nginx | `client_max_body_size` (brak w `default.conf`) | **1 MB** (default) | `413` na `/livewire/upload-file` → Filament: błąd uploadu/spinner |
| PHP | `upload_max_filesize` (brak w ini) | **2 MB** (default) | plik odrzucony (`UPLOAD_ERR_INI_SIZE`) |
| PHP | `post_max_size` | 8 MB | body odrzucone |
| PHP | `memory_limit` | 128 MB | przy resize dużego JPEG (np. 24 Mpx ≈ 100+ MB RAM w GD) → fatal |
| Livewire | `temporary_file_upload.rules` (config nie opublikowany) | `max:12288` (12 MB) | walidacja 422 |
| Filament | `FileUpload::maxSize(5120)` obrazy / `maxSize(20480)` pliki | 5 MB / 20 MB | walidacja formularza |

Efektywny limit = **1 MB** (nginx). Do podniesienia w klonie (wartości pod foto):

```nginx
# docker/nginx/default.conf, blok server
client_max_body_size 64m;
fastcgi_read_timeout 300;
```
```ini
; docker/php/Dockerfile -> 99-*.ini
upload_max_filesize=64M
post_max_size=64M
memory_limit=512M
max_execution_time=120
```
```php
// config/livewire.php (php artisan livewire:publish --config)
'temporary_file_upload' => ['rules' => ['required', 'file', 'max:65536']],
// FileUpload::make(...)->maxSize(65536)
```

## 7. Rekomendowana implementacja przetwarzania obrazów (dla klonu)

**Biblioteka:** `composer require intervention/image` (v3; driver GD lub Imagick).
**Dockerfile:** dodać `libwebp-dev` i `--with-webp` (GD) *lub* `libmagickwand-dev` + `pecl install imagick && docker-php-ext-enable imagick`.

```php
// app/Support/ProjectMedia.php (szkic)
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;   // lub Imagick\Driver

public static function process(string $sourcePath): array
{
    $disk = Storage::disk('public');
    $manager = new ImageManager(new Driver());
    $image = $manager->read($disk->path($sourcePath));   // auto-orientacja EXIF w v3: ->orient() jeśli potrzeba

    $base = 'projects/images/' . Str::ulid();

    $large = $image->scaleDown(width: 1200, height: 900);          // bez rozciągania, zachowuje proporcje
    $disk->put("$base-large.webp", (string) $large->toWebp(quality: 82));

    $thumb = $manager->read($disk->path($sourcePath))->cover(300, 300);   // crop 1:1
    $disk->put("$base-thumb.webp", (string) $thumb->toWebp(quality: 80));

    $disk->delete($sourcePath);                                     // bez oryginału (decyzja plan §7)

    return ['path_large' => "$base-large.webp", 'path_thumb' => "$base-thumb.webp"];
}
```

Wpięcie: w `ProjectImageObserver::creating/updating` zamiast `makeThumb()` wywołaj `process()` i
nadpisz **oba** pola (`path_large` też, bo zmienia się rozszerzenie). Alternatywnie
`FileUpload::saveUploadedFileUsing()` zwracające ścieżkę large i observer robiący thumb.
Przy dużych plikach rozważ **Job w kolejce** (`database` + worker) i placeholder do czasu przetworzenia.

Dodatkowo dla foto: zachowaj orientację EXIF (`$image->orient()`), usuń metadane w wersjach
publicznych (Intervention/GD robi to domyślnie przy re‑encode), zapisz wymiary do DB
(`$image->width()/height()`), opcjonalnie odczytaj EXIF (`exif_read_data()` — ext `exif` już jest w obrazie).

## 8. Gotowość na S3

- Cały kod używa `Storage::disk('public')` i przechowuje ścieżki względne → migracja = nowy dysk
  (`s3` w `config/filesystems.php`, `composer require league/flysystem-aws-s3-v3`), zmiana
  `->disk('public')` w `FileUpload` i w observerach/helperach na `config('media.disk')`, a
  `asset('storage/'.$path)` → `Storage::disk($disk)->url($path)`.
- `copy()`/`delete()` Flysystem działają na S3 identycznie; `Storage::disk()->path()` (używane do
  odczytu w Intervention) **nie działa na S3** → czytaj przez `get()`/stream.

## 9. Checklista przy modyfikacji tego obszaru

- [ ] `saveUploadedFileUsing` zwraca `string|null` (nigdy array).
- [ ] Pole uploadu na edycji: `formatStateUsing([])` + `dehydrated(filled)`.
- [ ] Observer obsługuje tylko `is_string($path)` (nie `UploadedFile`).
- [ ] Zamiana pliku kasuje stare large **i** thumb.
- [ ] `deleting` kasuje oba pliki; usunięcie rodzica kasuje dzieci przez Eloquent (nie tylko FK).
- [ ] `storage:link` istnieje; `APP_URL` zgodny z hostem.
- [ ] Limity nginx/PHP/Livewire/Filament spójne i ≥ największego oczekiwanego pliku.
- [ ] Testy: upload przez `Livewire::test(ImagesRelationManager::class, [...])` lub Feature test
      z `Storage::fake('public')` + bezpośrednie `ProjectImage::create()` sprawdzające observer.
