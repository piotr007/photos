# 02 — Środowisko deweloperskie (Docker), runbook, narzędzia

## 1. Usługi `docker-compose.yml`

| Usługa | Obraz | Kontener | Porty (host→kont.) | Wolumeny | Rola |
|---|---|---|---|---|---|
| `app` | build `docker/php/Dockerfile` (`php:8.3-fpm`) | `3dprint-app` | brak (9000 wewn.) | `./:/var/www/html` | PHP‑FPM + Composer + artisan; `depends_on: db (service_healthy)` |
| `web` | `nginx:1.27-alpine` | `3dprint-web` | `${WEB_PORT:-8080}:80` | `./:/var/www/html`, `./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro` | serwuje `public/`, fastcgi → `app:9000` |
| `db` | `mysql:8.4` | `3dprint-db` | `${DB_PORT:-3306}:3306` | `mysql-data:/var/lib/mysql` (named volume) | MySQL; healthcheck `mysqladmin ping` (10 s × 10, start 25 s) |
| `mailpit` | `axllent/mailpit:latest` | `3dprint-mailpit` | `${MAILPIT_PORT:-8025}:8025` | — | łapie całą pocztę; SMTP `mailpit:1025` wewnątrz sieci |

Sieć: `3dprint-net` (bridge). Zmienne compose (`MYSQL_DATABASE/USER/PASSWORD/ROOT_PASSWORD`,
`WEB_PORT`, `DB_PORT`, `MAILPIT_PORT`) mają defaulty: baza `3dprint`, user `3dprint`, hasło
`3dprint`, root `root`. Compose czyta je z `.env` w katalogu projektu (ten sam plik co Laravel).

> W klonie projektu zmień nazwy kontenerów/sieci (`3dprint-*`) — inaczej konflikt przy równoległym
> uruchomieniu obu projektów na jednym hoście. Zmień też `WEB_PORT`, `DB_PORT`, `MAILPIT_PORT`.

## 2. Obraz PHP — `docker/php/Dockerfile`

```dockerfile
FROM php:8.3-fpm
RUN apt-get install ... git curl unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libonig-dev
 && docker-php-ext-configure gd --with-freetype --with-jpeg
 && docker-php-ext-install pdo_mysql mbstring zip exif gd intl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
RUN mkdir -p /var/www/html/storage/tmp && chown -R www-data:www-data /var/www/html/storage
RUN { log_errors=On; display_errors=Off; sys_temp_dir=/var/www/html/storage/tmp; upload_tmp_dir=/var/www/html/storage/tmp } > /usr/local/etc/php/conf.d/99-3dprint.ini
```

Fakty istotne dla klonu:

- **`intl` jest obowiązkowe dla Filamenta** (bez niego 500 na `/admin`) — to był pierwszy fix w
  historii projektu.
- **GD bez WebP** — brak `libwebp-dev` i `--with-webp` → `imagewebp()` nie istnieje. Do generowania
  WebP dodaj: `libwebp-dev` do apt oraz `--with-webp` do `docker-php-ext-configure gd`.
  Alternatywa: `pecl install imagick` + `libmagickwand-dev` (lepsza jakość resize, EXIF, ICC).
- **Limity PHP nie są podniesione** — obraz `php:*-fpm` nie ma `php.ini`, więc obowiązują wartości
  wbudowane: `upload_max_filesize=2M`, `post_max_size=8M`, `memory_limit=128M`,
  `max_execution_time=30` (dla FPM 0 = bez limitu przy CLI). Dla portfolio foto trzeba dopisać do
  `99-*.ini`: `upload_max_filesize=64M`, `post_max_size=64M`, `memory_limit=512M`,
  `max_execution_time=120`.
- **`sys_temp_dir`/`upload_tmp_dir` = `storage/tmp`** — w połączeniu z `TMPDIR` w `.env` rozwiązuje
  problem uploadów Livewire, które bez tego kończyły się błędem (brak zapisu do `/tmp` w kontenerze
  przy montowanym wolumenie / niespójnych uprawnieniach).
- Kontener działa jako **root** w `docker compose exec`, a PHP‑FPM jako `www-data` → patrz §6.
- Brak Node w obrazie — `npm` uruchamiasz **na hoście**.

## 3. nginx — `docker/nginx/default.conf`

```nginx
server {
    listen 80; server_name _;
    root /var/www/html/public; index index.php index.html;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include fastcgi_params; fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_index index.php; }
    location ~ /\.ht { deny all; }
}
```

- **Brak `client_max_body_size`** → domyślnie **1 MB**; większy upload = `413 Request Entity Too Large`
  (Livewire pokaże błąd uploadu lub „kręciołek"). Dla foto: `client_max_body_size 64m;` w bloku
  `server`. Warto też `fastcgi_read_timeout 300;` przy przetwarzaniu obrazów w żądaniu.
- Pliki `/storage/*` serwuje nginx bezpośrednio przez symlink `public/storage` (statyki bez PHP).

## 4. Zmienne środowiskowe

### 4.1 Rzeczywisty `.env` dev (klucze; wartości sekretne pominięte)

```ini
APP_NAME=3dprint
APP_ENV=local
APP_KEY=base64:...            # php artisan key:generate
APP_DEBUG=true
APP_URL=http://3dprint.local:8080
TMPDIR=/var/www/html/storage/tmp

DB_CONNECTION=mysql
DB_HOST=db                    # nazwa usługi compose
DB_PORT=3306
DB_DATABASE=3dprint
DB_USERNAME=3dprint
DB_PASSWORD=3dprint

# Mail via Mailpit (dev): UI at http://localhost:8025
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS="noreply@3dprint.local"
MAIL_FROM_NAME="${APP_NAME}"
QUEUE_CONNECTION=sync         # KRYTYCZNE — patrz 09-troubleshooting
```

Pozostałe klucze biorą defaulty z `config/*.php`: `SESSION_DRIVER=database` (tabela `sessions`
z migracji users), `CACHE_STORE=database` (tabela `cache`), `FILESYSTEM_DISK=local`,
`LOG_CHANNEL=stack`.

### 4.2 Szablony

| Plik | Zawartość | Użycie |
|---|---|---|
| `.env.example` | pełny szablon Laravel 13 + blok Mailpit + `QUEUE_CONNECTION=sync`, ale `DB_CONNECTION=sqlite`, `APP_URL=http://localhost:8000` | referencja pełnej listy kluczy |
| `.env.docker.example` | 12 linii: APP_*, TMPDIR, DB_* pod MySQL w Dockerze; **brak MAIL_*/QUEUE_*** | punkt startowy dla Dockera (uzupełnij pocztę i kolejkę!) |

Zalecenie dla klonu: jeden kompletny `.env.docker.example` zawierający wszystko z §4.1.

### 4.3 `/etc/hosts` na hoście

```
127.0.0.1 3dprint.local
```

`APP_URL` musi odpowiadać adresowi, pod którym otwierasz stronę — Filament/Livewire generują
absolutne URL‑e (uploady, assety) na jego podstawie; niezgodność = błędy CORS/404 assetów.

## 5. Runbook

### 5.1 Pierwsze uruchomienie (świeży klon)

```bash
cd /path/to/project
cp .env.docker.example .env               # + dopisz blok MAIL_* i QUEUE_CONNECTION=sync
mkdir -p storage/tmp storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache
echo "127.0.0.1 3dprint.local" | sudo tee -a /etc/hosts
docker compose up -d --build              # czeka na healthcheck db
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec -u www-data app php artisan storage:link
docker compose exec -u www-data app php artisan migrate --seed
docker compose exec app sh -c 'chown -R www-data:www-data storage bootstrap/cache'
nvm use 20 && npm install && npm run build   # na hoście; tworzy public/build/manifest.json
curl -I http://3dprint.local:8080/ && curl -I http://3dprint.local:8080/admin/login
```

Login: `admin@3dprint.local` / `password`. Mailpit: `http://localhost:8025`.

### 5.2 Codzienna praca

```bash
docker compose up -d
npm run dev                                  # HMR (Vite) — albo npm run build po każdej zmianie CSS/JS
docker compose exec -u www-data app php artisan migrate
docker compose exec -u www-data app php artisan db:seed          # idempotentne
docker compose exec -u www-data app php artisan tinker
docker compose exec -u www-data app php artisan test
docker compose exec app php artisan optimize:clear               # po zmianie .env / config
docker compose logs -f app web
```

### 5.3 Po zmianie Dockerfile

```bash
docker compose build app && docker compose up -d --no-deps app
docker compose exec app php -m | grep -iE 'intl|gd|exif'
```

### 5.4 Reset bazy

```bash
docker compose exec -u www-data app php artisan migrate:fresh --seed
```

Uwaga: `migrate:fresh` nie usuwa plików w `storage/app/public/projects/**` (observery nie odpalają
się przy DROP TABLE) — sprzątaj ręcznie: `rm -rf storage/app/public/projects/*`.

### 5.5 Backup / restore MySQL — `scripts/db.sh`

```bash
./scripts/db.sh backup                 # -> backups/mysql/backup_YYYYMMDD_HHMMSS.sql (mysqldump w kontenerze db)
./scripts/db.sh restore backups/mysql/backup_....sql
```

Skrypt używa `docker compose exec -T db` i zmiennych `MYSQL_*` z kontenera; `backups/` nie jest w
`.gitignore` (dodaj w klonie!). Backup nie obejmuje plików mediów — te kopiuj z
`storage/app/public/`.

## 6. Uprawnienia (najczęstsza przyczyna błędów 500)

- Wolumen `./:/var/www/html` → pliki należą do użytkownika hosta (`ps`), a `storage/` do `www-data`
  (po `chown` w Dockerfile/komendach). PHP‑FPM działa jako `www-data`.
- `docker compose exec app ...` = **root**. Każda komenda kompilująca widoki (`test`, `tinker` z
  `view()`, `view:cache`, wejście na stronę przez CLI) lub piszące cache tworzy pliki root‑owe →
  później PHP‑FPM nie może ich `touch()`: `ErrorException: Utime failed: Operation not permitted`.
- **Zasada:** `docker compose exec -u www-data app php artisan ...`.
- **Naprawa:** `docker compose exec app sh -c 'php artisan view:clear && chown -R www-data:www-data storage bootstrap/cache'`.
- Pliki utworzone przez kontener na hoście (np. `.phpunit.result.cache`, `vendor/`) mogą należeć do
  roota → `sudo chown -R $USER:$USER .` (było w historii projektu).

## 7. Frontend build (host)

- Wymagany Node ≥ 18 (Vite 8, Tailwind 4). Historycznie Node 12 → błąd; użyto `nvm install 20`.
- `npm run build` → `public/build/{manifest.json,assets/*}`; `public/build` jest w `.gitignore` →
  **po każdym klonie / deployu trzeba zbudować**, inaczej `Vite manifest not found`.
- `npm run dev` uruchamia serwer HMR na hoście (`public/hot`); z kontenerem działa, bo przeglądarka
  łączy się bezpośrednio z hostem. Jeśli kłopoty — `npm run build` i usuń `public/hot`.
- `vite.config.js`: `laravel({ input: ['resources/css/app.css','resources/js/app.js'], refresh: true, fonts: [bunny('Instrument Sans', {weights:[400,500,600]})] })`, `tailwindcss()`, `server.watch.ignored: ['**/storage/framework/views/**']`.

## 8. Poczta (Mailpit)

- Cała poczta z aplikacji (reset hasła Filamenta, kody 2FA e‑mail) trafia do Mailpit; UI
  `http://localhost:8025`, API `http://localhost:8025/api/v1/messages` (użyte w smoke‑teście).
- Powiadomienia Filamenta implementują `ShouldQueue` → przy `QUEUE_CONNECTION=database` bez
  workera **nic nie wychodzi** (joby leżą w tabeli `jobs`). Dev: `sync`. Prod z ruchem: `database` +
  `php artisan queue:work` jako proces/kontener. Ratunek dla zaległych jobów:
  `php artisan queue:work database --once --stop-when-empty`.

## 9. Pakowanie i deploy (ręczny FTP)

| Skrypt | Wynik | Wyklucza |
|---|---|---|
| `zip-project.sh` | `../3dprint_YYYYMMDD_HHMM.zip` | `storage/`, `bootstrap/cache/`, `vendor/`, `node_modules/` |
| `deploy-package.sh` | `../3dprint_deploy_YYYYMMDD_HHMM.zip` | jw. + `.git`, `.idea`, `.github`, `.env*`, `public/storage`, `backups`, `docker`, `tests`, `database/database.sqlite`, `*.log`, cała dokumentacja PL/EN (`plan.md`, `commands-log.md`, `AGENTS.md`, …), `zip-project.sh`, `docs/` |

Deploy na hostingu wymaga ręcznie: `composer install --no-dev --optimize-autoloader`,
`.env` produkcyjny (`APP_ENV=production`, `APP_DEBUG=false`, prawdziwy SMTP, `APP_KEY`
**ten sam co wcześniej** jeśli istnieją użytkownicy z 2FA), `php artisan migrate --force`,
`storage:link`, `optimize`, `npm run build` (lokalnie — `public/build` trafia do ZIP‑a, bo skrypt
go nie wyklucza — upewnij się, że build jest świeży przed pakowaniem!), document root → `public/`.

## 10. IDE / narzędzia pomocnicze

- `.editorconfig` standard Laravel (4 spacje, LF).
- `.idea/` (PhpStorm) w `.gitignore`.
- `.github/agents/test.agent.md` — pusty szablon custom agenta Copilota.
- `laravel/pint` dostępny: `docker compose exec app vendor/bin/pint --test`.
- `laravel/pail`: `docker compose exec app php artisan pail` (live logi).
