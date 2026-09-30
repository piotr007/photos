# AGENTS.md — guide for AI agents working on this repository

Photographer portfolio: Laravel 13 + Filament 5 (CMS at `/admin`) + Blade/Tailwind 4 public site.
Architectural clone of the `3dprint` project; its full handover documentation lives in
`docs/reference-3dprint/` (read `08-blueprint-portfolio-foto.md` and `09-troubleshooting.md` first).
Planning docs (Polish): `plan.md` (decisions §12, stage status §11), `commands-log.md` (command log).

## Golden rules

1. **Media only through Eloquent.** `PhotoObserver` turns the raw upload path in `path_large` into
   three WebP files, fills dimensions + EXIF and deletes files on replace/delete. Never delete
   `photos`/`albums` rows with raw SQL or `DB::table()`; never write derivative paths by hand.
2. **EXIF fills only empty fields.** Admin-entered shooting data has priority (`PhotoObserver::applyProcessed`).
3. **The upload field pattern is deliberate** (`Schemas/PhotoForm.php`): `formatStateUsing(fn () => [])`
   + `dehydrated(fn ($state) => filled($state))` + a `Placeholder` preview. Changing it re-introduces
   the "endless FilePond spinner" and "path_large cannot be null" bugs documented in
   `docs/reference-3dprint/09-troubleshooting.md`.
4. **Filament 5 ≠ v3 examples.** `Filament\Schemas\Schema`, `Filament\Schemas\Components\{Section,Group}`,
   `Filament\Schemas\Components\Utilities\{Get,Set}`, `Filament\Actions\*` for tables too,
   `->recordActions()` / `->toolbarActions()`, typed statics (`string|BackedEnum|null $navigationIcon`).
   Resources are split into `Schemas/*Form.php` and `Tables/*Table.php`.
5. **Seeders are idempotent** (`updateOrCreate`); dev credentials only in `local`/`testing`.
6. **Run artisan as `www-data` in Docker:** `docker compose exec -u www-data app php artisan …`.
7. **Log commands** in `commands-log.md` and **record decisions** in `plan.md` §12.
8. Language: public UI and URLs in English, code identifiers in English, planning docs in Polish.

## Where things are

| Concern | Files |
|---|---|
| Image pipeline (sizes, WebP, EXIF, cleanup) | `app/Support/PhotoMedia.php`, `app/Observers/PhotoObserver.php`, `config/media.php` |
| Photo editing UI (all metadata, bulk upload, tags, cover) | `app/Filament/Admin/Resources/Albums/Schemas/PhotoForm.php`, `…/RelationManagers/PhotosRelationManager.php` |
| Album CRUD | `app/Filament/Admin/Resources/Albums/{AlbumResource,Schemas/AlbumForm,Tables/AlbumsTable}.php` |
| Tags | `app/Filament/Admin/Resources/Tags/TagResource.php`, `app/Models/Tag.php` |
| Public routes/controllers | `routes/web.php`, `app/Http/Controllers/{Home,Album,Photo,Tag}Controller.php` |
| Public views | `resources/views/{layouts/app,home,albums/*,photos/show,tags/show,about,errors/404}.blade.php` |
| Lightbox | `resources/js/glightbox.js` (+ description styles in `resources/css/app.css`) |
| Auth / 2FA | `app/Models/User.php`, `app/Providers/Filament/AdminPanelProvider.php` |
| Tests | `tests/Feature/*`, `tests/Unit/*`, JPEG+EXIF generator `tests/Support/ExifJpeg.php` |

## Commands

```bash
php artisan test                 # SQLite :memory:, withoutVite() in TestCase
vendor/bin/pint --test           # style (Laravel preset)
npm run build                    # required before opening the site (public/build is git-ignored)
```

Without Docker you need PHP 8.3 with `intl`, `gd` (WebP), `exif`, `pdo_sqlite`; on this
workstation they were installed user-space – `source ~/.local/photo-env.sh` puts `php`,
`composer`, `node` on PATH and points `PHPRC` at a `php.ini` with `memory_limit=512M`.

## Known gaps / next steps

See `plan.md` §11 (stages 7–9): Docker smoke test, manual browser test of large uploads,
editable About page (`settings`), contact form, queue for very large bulk uploads, S3, watermark.
