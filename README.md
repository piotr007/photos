# Photo Portfolio

Photographer's portfolio: public galleries (dark theme, masonry grid, lightbox) plus a CMS
(Filament 5) where **every photo carries its own detailed information** – title, caption,
story, alt text, tags and full shooting data (camera, lens, focal length, aperture, shutter
speed, ISO, date, location). Shooting data is read from EXIF on upload and can be edited freely.

Built as an architectural clone of the `3dprint` project – see `docs/reference-3dprint/`
for the reference documentation this project was derived from.

## Stack

| Layer | Technology |
|---|---|
| Runtime | PHP 8.3 (`php:8.3-fpm` image, GD with WebP, exif, intl) |
| Framework | Laravel 13 |
| CMS | Filament 5 (login, password reset, profile, optional 2FA: TOTP + e-mail codes) |
| Images | Intervention Image v3 (GD or Imagick) → 3 WebP derivatives, originals are **not** kept |
| Database | MySQL 8.4 in Docker (dev) · SQLite `:memory:` for tests |
| Frontend | Blade + Tailwind CSS 4 (`@tailwindcss/typography`) + Vite 8 + GLightbox |
| Mail (dev) | Mailpit |

## Domain

```
Album 1─N Photo N─M Tag
```

* **Album** (`/galleries/{slug}`): title, slug, short description, rich-text description,
  technical notes, shot date, location, published/featured flags, manual sort order.
* **Photo** (`/galleries/{album}/{photo}`): slug (unique per album), title, caption, rich-text
  description, alt, `path_large|medium|thumb`, original file name, width/height, cover flag,
  sort order, `taken_at`, location, camera, lens, focal length, aperture, shutter speed, ISO.
* **Tag** (`/tags/{slug}`): name + slug, many-to-many with photos.

## Media pipeline

`FileUpload` (Filament) stores the raw upload on the media disk → `PhotoObserver::creating`
calls `App\Support\PhotoMedia::process()`:

1. reads EXIF from the original (before it is stripped),
2. auto-orients, scales down to **large** (≤ 2560 px), **medium** (≤ 1600 px) and crops a
   square **thumb** (600 px) – all WebP, metadata stripped (GPS never leaks),
3. deletes the original, fills `path_*`, `width`, `height` and any **empty** shooting-data field.

Replacing a photo deletes the previous three files; deleting a photo or an album deletes all
files (albums delete photos through Eloquent so observers run). Exactly one cover per album;
the first uploaded photo becomes the cover automatically.

Sizes, quality, disk, driver and memory limit live in `config/media.php` (`MEDIA_*` env vars).

## Quick start (Docker)

```bash
cp .env.docker.example .env
mkdir -p storage/tmp storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache
echo "127.0.0.1 photo.local" | sudo tee -a /etc/hosts
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec -u www-data app php artisan storage:link
docker compose exec -u www-data app php artisan migrate --seed
docker compose exec app sh -c 'chown -R www-data:www-data storage bootstrap/cache'
npm install && npm run build            # host, Node >= 18
```

* Site: <http://photo.local:8081> · Admin: <http://photo.local:8081/admin>
  (`admin@photo.local` / `password` – dev seed only) · Mailpit: <http://localhost:8026>
* Ports/names differ from `3dprint` (8081/3307/8026, `photo-*` containers) so both stacks can run side by side.
* Always run artisan as `www-data` inside the container (`docker compose exec -u www-data app php artisan …`)
  to avoid root-owned files in `storage/`.

## Quick start (without Docker)

Any PHP 8.3 with `intl`, `gd` (WebP), `exif`, `pdo_sqlite`/`pdo_mysql` works, e.g. a
[static-php](https://static-php.dev) "bulk" build. `.env` with `DB_CONNECTION=sqlite` and
`APP_URL` matching the address you open (image URLs are built from `APP_URL`):

```bash
composer install && npm install && npm run build
php artisan key:generate && php artisan migrate --seed && php artisan storage:link
php artisan serve --port=8090        # APP_URL=http://127.0.0.1:8090
```

Set `memory_limit=512M` (and 64M upload limits) in `php.ini` – full-resolution photos do not
fit into PHP's default 128M while resizing (`config/media.php` raises it at runtime when allowed).

## Everyday commands

```bash
php artisan test                      # 41 tests, SQLite in memory, no Vite build needed
vendor/bin/pint --test                # code style
npm run dev                           # Vite HMR
php artisan migrate:fresh --seed      # reset (does NOT remove files in storage/app/public/albums)
./scripts/db.sh backup|restore <file> # MySQL dump via docker compose
./deploy-package.sh                   # ZIP for manual FTP deploy (run npm run build first)
```

## Project map

```
app/
├── Filament/Admin/Resources/Albums/      AlbumResource, Pages, Schemas/{AlbumForm,PhotoForm}, Tables/AlbumsTable,
│                                         RelationManagers/PhotosRelationManager (single + bulk upload, tags, cover)
├── Filament/Admin/Resources/Tags/        TagResource (+ ManageTags page)
├── Http/Controllers/                     Home, Album, Photo, Tag
├── Models/                               Album, Photo, Tag, User (Filament MFA contracts)
├── Observers/PhotoObserver.php           process on create/replace, cleanup on delete, single cover
├── Providers/                            AppServiceProvider (observer, nav composer), Filament/AdminPanelProvider
└── Support/PhotoMedia.php                resize/WebP/EXIF pipeline
config/media.php                          disk, dirs, driver, sizes, quality, limits
database/migrations/                      users(+2FA), albums, photos, tags + photo_tag
database/seeders/DatabaseSeeder.php       idempotent; admin user only in local/testing
resources/views/                          layouts/app (dark), home, albums/{index,show}, photos/show, tags/show,
                                          about, errors/404, partials/album-tile, filament/photo-preview,
                                          vendor/pagination/portfolio
resources/{css/app.css,js/glightbox.js}   Tailwind 4 + typography, GLightbox init and description styles
docker/, docker-compose.yml, scripts/     dev stack (php-fpm 8.3 + nginx + MySQL 8.4 + Mailpit)
tests/                                    Feature: AdminAuth, AdminPanel (Livewire), PhotoPipeline, PublicSite, Seeder
                                          Unit: PhotoMediaExif · Support/ExifJpeg (JPEG+EXIF generator)
docs/reference-3dprint/                   handover documentation of the source project
plan.md, commands-log.md                  decisions / stage status, command log (PL)
```

## Troubleshooting (most common)

| Symptom | Fix |
|---|---|
| Images 404 on the public site | `php artisan storage:link`; `APP_URL` must match the address in the browser |
| `Allowed memory size exhausted` on upload | `memory_limit=512M` (Dockerfile has it) or `MEDIA_MEMORY_LIMIT` |
| `413` / upload spinner | nginx `client_max_body_size 64m` + PHP `upload_max_filesize`/`post_max_size` (both in `docker/`) |
| Password-reset mail never arrives | `QUEUE_CONNECTION=sync` (Filament notifications are queued) and `MAIL_HOST=mailpit` |
| `Utime failed` / 500 after running artisan as root | `docker compose exec app sh -c 'php artisan view:clear && chown -R www-data:www-data storage bootstrap/cache'` |
| `Vite manifest not found` | `npm run build` (tests call `withoutVite()`) |

More: `docs/reference-3dprint/09-troubleshooting.md`.
