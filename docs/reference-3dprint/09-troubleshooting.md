# 09 — Troubleshooting: objaw → przyczyna → rozwiązanie

Wszystko poniżej **wystąpiło realnie** w historii projektu (źródło: `commands-log.md`, `AGENTS.md`)
albo jest **przewidywane** na podstawie konfiguracji (oznaczone ⚠️ *przewidywane*). Kolejność: od
najczęstszych.

## Środowisko / Docker

| Objaw | Przyczyna | Rozwiązanie |
|---|---|---|
| `ErrorException: touch(): Utime failed: Operation not permitted` (np. na `/admin/profile`), 500 po uruchomieniu testów/tinkera | pliki w `storage/framework/views` (lub cache) utworzone jako **root** przez `docker compose exec app php artisan …`; PHP‑FPM (`www-data`) nie może ich nadpisać | `docker compose exec app sh -c 'php artisan view:clear && chown -R www-data:www-data storage bootstrap/cache'`; na przyszłość `exec -u www-data` |
| 500 na `/admin`, w logu brak klasy `NumberFormatter` / `intl` | brak rozszerzenia PHP `intl` (Filament wymaga) | Dockerfile: `libicu-dev` + `docker-php-ext-install intl` → `docker compose build app && docker compose up -d --no-deps app` |
| Upload w Filamencie kończy się błędem / plik tymczasowy nie powstaje; `file_put_contents(/tmp/...)` failed | brak stabilnego katalogu tmp dla PHP w kontenerze | `.env`: `TMPDIR=/var/www/html/storage/tmp`; Dockerfile: `sys_temp_dir`/`upload_tmp_dir` na ten katalog + `mkdir -p storage/tmp`; `docker compose down && up -d` |
| `No application encryption key has been specified` | brak `APP_KEY` po `cp .env.*.example .env` | `docker compose exec app php artisan key:generate && php artisan config:clear` |
| `Vite manifest not found at: public/build/manifest.json` | brak buildu (katalog `public/build` w `.gitignore`) | `npm install && npm run build` na hoście; w testach `$this->withoutVite()` |
| `npm run build` → błędy składni / `SyntaxError: Unexpected token` / wymagania engine | Node 12 na hoście; Vite 8 wymaga Node ≥ 18 | `nvm install 20 && nvm use 20 && npm run build` |
| Strona pod `http://3dprint.local:8080` nie odpowiada, `curl` do `127.0.0.1:8080` działa | brak wpisu w `/etc/hosts` | `echo "127.0.0.1 3dprint.local" \| sudo tee -a /etc/hosts` |
| Livewire: 404 na `/livewire/update`, assety Filamenta z innego hosta, błędy CORS | `APP_URL` ≠ adres w przeglądarce | ustaw `APP_URL` zgodnie z hostem/portem; `php artisan optimize:clear` |
| Migracje „już wykonane"/stan niespójny; aplikacja korzysta z `database/database.sqlite` | `.env` z `DB_CONNECTION=sqlite` (z `.env.example`) zamiast MySQL | `cp .env.docker.example .env` (+ uzupełnij MAIL/QUEUE), `optimize:clear`, `migrate:fresh --seed` |
| `docker compose up` — `app` nie startuje, czeka na `db` | healthcheck MySQL (start_period 25 s) | poczekać; `docker compose logs db`; przy zmianie hasła użytkownika usuń wolumen `mysql-data` |
| Pliki projektu na hoście należą do `root` (`git status` pokazuje wszystko jako zmienione / brak zapisu w IDE) | komendy `composer`/`artisan` przez `exec` jako root tworzyły pliki | `sudo chown -R $USER:$USER .` (poza `storage/` → `www-data`) |
| Po `migrate:fresh` brak konta admina | `fresh` czyści `users` | `php artisan db:seed` (idempotentny) |

## Filament / panel

| Objaw | Przyczyna | Rozwiązanie |
|---|---|---|
| Po edycji `alt`/`sort_order` obrazu: `SQLSTATE[23000] … Column 'path_large' cannot be null` | puste pole `FileUpload` dehydratowało `null` i nadpisało kolumnę | `->dehydrated(fn ($state) => filled($state))` na polu uploadu |
| Na edycji obrazu FilePond kręci się w nieskończoność (podgląd istniejącego pliku) | `FileUpload` próbuje załadować istniejący plik (URL/metadane) | `->formatStateUsing(fn () => [])` + osobny `Placeholder` z `<img>` na podgląd |
| `storeFileName(): Argument #1 ($file) must be of type string, array given` | `saveUploadedFileUsing()` zwróciło tablicę (np. `['path_large'=>…, 'path_thumb'=>…]`) | closure musi zwracać `string\|null` (ścieżkę); resztę robi observer |
| Miniatura w panelu nie zmienia się po zamianie zdjęcia | cache przeglądarki (ta sama ścieżka?) / brak cache‑bustera | partial używa `?v={{ updated_at->timestamp }}`; sprawdź czy observer nadpisał `path_thumb` |
| `Class "Filament\Forms\Form" not found` / `Section` z `Filament\Forms\Components` nie działa jak layout | przykłady z Filament v3 | Filament 5: `Filament\Schemas\Schema`, `Filament\Schemas\Components\{Section,Group}`, `Filament\Schemas\Components\Utilities\Get` — patrz `04-…` §7 |
| `Table::actions()` deprecated / brak akcji w wierszu | zmiana API | `->recordActions([...])`, bulk `->toolbarActions([...])` |
| `Typed static property … $navigationIcon must be string\|BackedEnum\|null` | Filament 5 typuje statics | `protected static string\|\BackedEnum\|null $navigationIcon = 'heroicon-o-…';` |
| Po zalogowaniu 403 (na serwerze, `APP_ENV=production`) | `User` nie implementuje `FilamentUser` | zaimplementowane (`canAccessPanel(): true`) — sprawdź, czy klon też ma |
| Redirect z `/admin` na nieistniejącą trasę `login` (historyczne) | brak `->login()` w panelu / stare własne trasy | panel ma `->login()`; własne `/login` usunięte |
| Relation manager nie widoczny | jesteś na stronie **create** | RM działają tylko na edit (potrzebny rekord nadrzędny) |

## Poczta / auth

| Objaw | Przyczyna | Rozwiązanie |
|---|---|---|
| „Forgot password" — komunikat sukcesu, ale mail nie dochodzi do Mailpit; tabela `jobs` rośnie | powiadomienia Filamenta są `ShouldQueue`; `QUEUE_CONNECTION=database` bez workera | `QUEUE_CONNECTION=sync` (dev) lub `php artisan queue:work`; zaległe: `php artisan queue:work database --once --stop-when-empty` |
| Mail idzie do logów zamiast Mailpit | `MAIL_MAILER=log` (default z `config/mail.php`) — `.env.docker.example` nie ma sekcji MAIL | dopisz `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, `MAIL_FROM_ADDRESS` |
| Po zmianie `APP_KEY` użytkownicy z 2FA nie mogą się zalogować (`DecryptException`) | sekret TOTP/kody odzyskiwania szyfrowane `APP_KEY` | nie zmieniaj klucza na prod; awaryjnie wyzeruj `app_authentication_secret`, `app_authentication_recovery_codes`, `has_email_authentication` |
| Zgubiony telefon (TOTP) | brak kodów odzyskiwania | tinker: `User::where('email',…)->update(['app_authentication_secret'=>null,'app_authentication_recovery_codes'=>null])` |

## Baza / migracje / testy

| Objaw | Przyczyna | Rozwiązanie |
|---|---|---|
| Testy: `duplicate column name: is_featured` na sqlite | kolumna w `create_project_images_table` **i** w późniejszej migracji ALTER | guard `if (Schema::hasColumn(...)) return;` (już jest); w klonie nie dubluj |
| `ExampleTest` pada na `/` z `ViteManifestNotFoundException` | brak `public/build` w środowisku testowym | `npm run build` lub `withoutVite()` w `TestCase::setUp()` |
| `db:seed` na produkcji zresetował hasło admina | `updateOrCreate` nadpisuje `password` | seed dev‑only (`app()->isLocal()`), oddzielny `ProductionSeeder` bez haseł |
| Sortowanie listy: opublikowane projekty bez `published_at` lądują na końcu/początku | `published_at` może być `null` przy `is_published=true` | ustawiać automatycznie w `saving` (klon) |

## Media / pliki

| Objaw | Przyczyna | Rozwiązanie |
|---|---|---|
| Obrazy na stronie publicznej 404 (`/storage/projects/...`) | brak symlinku `public/storage` | `docker compose exec -u www-data app php artisan storage:link` |
| ⚠️ *przewidywane*: upload > 1 MB → błąd Livewire / `413` w konsoli | nginx `client_max_body_size` default 1 MB | `client_max_body_size 64m;` w `docker/nginx/default.conf`, restart `web` |
| ⚠️ *przewidywane*: upload 2–5 MB → „The file failed to upload" | PHP `upload_max_filesize=2M`, `post_max_size=8M` | ini w Dockerfile: `upload_max_filesize=64M`, `post_max_size=64M`; rebuild |
| ⚠️ *przewidywane*: `Call to undefined function imagewebp()` po dodaniu konwersji WebP | GD skompilowane bez `--with-webp` | Dockerfile: `libwebp-dev` + `--with-webp`; lub Imagick |
| ⚠️ *przewidywane*: `Allowed memory size exhausted` przy resize | `memory_limit=128M` a 24 Mpx bitmapa ≈ 100–200 MB | `memory_limit=512M`; Imagick zużywa mniej; kolejka |
| Stare pliki zostają po usunięciu projektu | `cascadeOnDelete` w DB omija observery | `Project::deleting` → `images->each->delete()` |
| Thumb wygląda identycznie jak large (wolna strona) | `makeThumb()` = kopia | zaimplementować resize (`05-…` §7) |
| `Storage::copy()` rzuca `UnableToCopyFile` / thumb brak | `path_large` wskazuje na nieistniejący plik (np. ręcznie usunięty), `throw=false` maskuje część błędów | sprawdź `storage/app/public/…`; usuń rekord i wgraj ponownie |

## Diagnostyka — komendy

```bash
docker compose logs -f app web                                        # logi PHP-FPM/nginx
docker compose exec app tail -f storage/logs/laravel.log              # log aplikacji (lub php artisan pail)
docker compose exec app php -i | grep -E 'upload_max_filesize|post_max_size|memory_limit|sys_temp_dir'
docker compose exec app php -m | grep -iE 'intl|gd|exif|imagick'
docker compose exec app php -r 'print_r(gd_info());'                  # "WebP Support" => true?
docker compose exec -u www-data app php artisan route:list --except-vendor
docker compose exec -u www-data app php artisan tinker --execute="dump(\App\Models\ProjectImage::latest('id')->first()?->toArray());"
docker compose exec app sh -lc 'find storage/app/public -type f | grep projects/'
docker compose exec app ls -la storage/framework/views | head          # właściciel plików (root?)
curl -s http://localhost:8025/api/v1/messages | head -c 500           # Mailpit API
```
