# Dziennik komend — Photo Portfolio

> Konwencja z 3dprint: każda istotna komenda + jednozdaniowy komentarz. Sekcje chronologicznie.
> Środowisko: host Linux Mint bez Dockera/PHP/Node (sudo z hasłem) → narzędzia user‑space w `~/.local`.
> Przed pracą: `source ~/.local/photo-env.sh` (ustawia PATH, nvm, PHPRC, COMPOSER_MEMORY_LIMIT).

## 2026-09-30 — Etap 0: narzędzia (user-space, bez sudo)

```bash
mkdir -p ~/.local/bin ~/.local/php83-bulk && cd ~/.local/php83-bulk
curl -sL -o php.tar.gz https://dl.static-php.dev/static-php-cli/bulk/php-8.3.32-cli-linux-x86_64.tar.gz
tar xzf php.tar.gz && cp php ~/.local/bin/php
```
Statyczne PHP 8.3.32 (build „bulk”: intl, gd+webp, imagick, exif, pdo_sqlite, pdo_mysql). Build „common” nie ma `intl` (Filament wymaga).

```bash
php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"   # + weryfikacja SHA-384 z installer.sig
php /tmp/composer-setup.php --install-dir="$HOME/.local/bin" --filename=composer
```
Composer 2.10 jako PHAR.

```bash
PROFILE=/dev/null bash <(curl -sL https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh)
. ~/.nvm/nvm.sh && nvm install 22 && nvm alias default 22
```
Node 22 przez nvm (bez modyfikacji `.bashrc` — ładowany z `~/.local/photo-env.sh`).

```bash
cat > ~/.local/etc/php.ini   # memory_limit=512M, upload_max_filesize=64M, post_max_size=64M, max_execution_time=300
export PHPRC="$HOME/.local/etc"
```
Statyczne PHP nie ma `php.ini` → domyślne 128M wywalało resize 3000×2000 (odpowiednik `99-photo.ini` z Dockerfile).

## Etap 1: szkielet

```bash
composer create-project laravel/laravel .laravel-skeleton --prefer-dist --no-interaction
shopt -s dotglob && mv .laravel-skeleton/* . && rmdir .laravel-skeleton
mkdir -p docs && mv 3dprint_ai-handover docs/reference-3dprint
```
Laravel 13.34 w katalogu repo; handover jako dokumentacja referencyjna (blueprint 08 §11).

```bash
composer require "filament/filament:^5.7" "intervention/image:^3.0" -W
php artisan filament:install --panels --no-interaction
npm install && npm install glightbox && npm install -D @tailwindcss/typography
```
Filament 5.9.0, Livewire 4.4.7, Intervention Image 3.11.9; panel `admin` + assety w `public/{css,js,fonts}/filament` (w `.gitignore`).

Ręcznie: `docker-compose.yml` (photo-*, porty 8081/3307/8026), `docker/php/Dockerfile` (+`libwebp-dev --with-webp`, ini 64M/512M/300s),
`docker/nginx/default.conf` (+`client_max_body_size 64m`, cache `/storage|/build`), `scripts/db.sh`, `deploy-package.sh`, `zip-project.sh`,
`.env.example`, `.env.docker.example` (kompletny — MAIL_*, QUEUE_CONNECTION=sync, MEDIA_*), `config/media.php`.

## Etap 2: baza, modele, pipeline

```bash
php artisan migrate:fresh          # users(+kolumny 2FA w create), albums, photos, tags+photo_tag — sqlite
php artisan storage:link
php artisan tinker /tmp/pipeline-test.php
```
Test pipeline'u: JPEG 3000×2000 → 3 WebP (2560×1707 / 1600×1067 / 600×600) w 1,25 s (GD), oryginał usunięty, slug/cover/published_at auto.

```bash
curl -sL -o /tmp/Canon_40D.jpg https://raw.githubusercontent.com/ianare/exif-samples/master/jpg/Canon_40D.jpg
php artisan tinker --execute='print_r(\App\Support\PhotoMedia::readExif("/tmp/Canon_40D.jpg"));'
```
Weryfikacja parsera EXIF na prawdziwym pliku (Canon EOS 40D, 135 mm, f/7.1, 1/160, ISO 100). Sample CC BY‑SA → **nie** dodany do repo;
zamiast tego własny generator `tests/Support/ExifJpeg.php`.

## Etap 3: panel

```bash
php artisan make:filament-resource Album --generate --no-interaction
php artisan make:filament-relation-manager AlbumResource photos title --no-interaction
```
Tylko po to, by poznać strukturę v5.9 (`Schemas/`, `Tables/`, `Pages/`) — pliki przepisane ręcznie (AlbumForm, AlbumsTable, PhotoForm,
PhotosRelationManager z akcją `bulkUpload`, TagResource z `ManageRecords`).

```bash
php artisan route:list --path=admin
```
Kontrola tras panelu (`/admin/albums`, `/admin/tags`, login, profile).

## Etap 4: front

```bash
npm run build
php artisan db:seed && php artisan db:seed         # idempotencja
php artisan tinker /tmp/demo-data.php              # 2 albumy × 4 zdjęcia (różne proporcje) + tagi + album nieopublikowany
php artisan serve --host=127.0.0.1 --port=8090 &
for u in / /galleries /galleries/mountains ...; do curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8090$u; done
```
Wszystkie strony 200/404 zgodnie z oczekiwaniami (scoped binding zdjęcia → 404 w obcym albumie). `APP_URL` w `.env` ustawiony na
`http://127.0.0.1:8090`, bo URL‑e obrazów (`Storage::url`) pochodzą z `APP_URL`.

## Etap 5: testy i jakość

```bash
php artisan test --compact           # 41 passed, 220 assertions
vendor/bin/pint                      # auto-fix stylu, potem --test = passed
```
Debug bulk uploadu w teście: `fillForm()` rozbija klucze z kropkami (`one.jpg`) na zagnieżdżone tablice — artefakt testów, w realnym
flow Filament ustawia całą mapę `original_filenames` w jednym żądaniu; w teście użyto ścieżek bez rozszerzenia.

## Do zrobienia na maszynie z Dockerem

```bash
cp .env.docker.example .env && docker compose up -d --build
docker compose exec app composer install && docker compose exec app php artisan key:generate
docker compose exec -u www-data app php artisan storage:link
docker compose exec -u www-data app php artisan migrate --seed
docker compose exec app php -r 'print_r(gd_info());'    # "WebP Support" => true
docker compose exec -u www-data app php artisan test
```
Runbook z `README.md`; sprawdzić limity: `docker compose exec app php -i | grep -E 'upload_max_filesize|memory_limit'`.
