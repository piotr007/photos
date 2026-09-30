# 10 — Kod źródłowy (snapshot kluczowych plików)

> Wygenerowano automatycznie: `2026-09-30 17:35`, commit `fa534d2`
> skryptem `docs/ai-handover/generate-code-appendix.sh`. **Nie edytuj ręcznie** — uruchom skrypt ponownie.
>
> Pliki `config/*.php` to niezmienione defaulty Laravel 13 (pominięte). Sekrety: `.env` nie jest dołączony (tylko szablony).

## Spis treści

- [Infrastruktura (Docker, nginx, skrypty)](#infrastruktura-docker-nginx-skrypty)
- [Konfiguracja projektu](#konfiguracja-projektu)
- [Bootstrap i providery](#bootstrap-i-providery)
- [Modele Eloquent](#modele-eloquent)
- [Observery i helper mediów](#observery-i-helper-mediów)
- [Panel Filament](#panel-filament)
- [Routing i kontrolery](#routing-i-kontrolery)
- [Widoki publiczne (Blade)](#widoki-publiczne-blade)
- [Assety front](#assety-front)
- [Migracje](#migracje)
- [Seeder i fabryki](#seeder-i-fabryki)
- [Testy](#testy)

---

## Infrastruktura (Docker, nginx, skrypty)

### `docker-compose.yml`

```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    container_name: 3dprint-app
    working_dir: /var/www/html
    volumes:
      - ./:/var/www/html
    depends_on:
      db:
        condition: service_healthy
    networks:
      - 3dprint-net

  web:
    image: nginx:1.27-alpine
    container_name: 3dprint-web
    ports:
      - "${WEB_PORT:-8080}:80"
    volumes:
      - ./:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - app
    networks:
      - 3dprint-net

  mailpit:
    image: axllent/mailpit:latest
    container_name: 3dprint-mailpit
    ports:
      - "${MAILPIT_PORT:-8025}:8025"
    networks:
      - 3dprint-net

  db:
    image: mysql:8.4
    container_name: 3dprint-db
    environment:
      MYSQL_DATABASE: ${MYSQL_DATABASE:-3dprint}
      MYSQL_USER: ${MYSQL_USER:-3dprint}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:-3dprint}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:-root}
    ports:
      - "${DB_PORT:-3306}:3306"
    volumes:
      - mysql-data:/var/lib/mysql
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h localhost -u$$MYSQL_USER -p$$MYSQL_PASSWORD --silent"]
      interval: 10s
      timeout: 5s
      retries: 10
      start_period: 25s
    networks:
      - 3dprint-net

networks:
  3dprint-net:
    driver: bridge

volumes:
  mysql-data:
```

### `docker/php/Dockerfile`

```dockerfile
FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring zip exif gd intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN mkdir -p /var/www/html/storage/tmp \
    && chown -R www-data:www-data /var/www/html/storage

RUN { \
    echo 'log_errors=On'; \
    echo 'display_errors=Off'; \
    echo 'sys_temp_dir=/var/www/html/storage/tmp'; \
    echo 'upload_tmp_dir=/var/www/html/storage/tmp'; \
} > /usr/local/etc/php/conf.d/99-3dprint.ini
```

### `docker/nginx/default.conf`

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_index index.php;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

### `scripts/db.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKUP_DIR="$PROJECT_ROOT/backups/mysql"

usage() {
  cat <<'EOF'
Usage:
  scripts/db.sh backup
  scripts/db.sh restore <path-to-sql-file>
EOF
}

if [[ $# -lt 1 ]]; then
  usage
  exit 1
fi

ACTION="$1"
mkdir -p "$BACKUP_DIR"
cd "$PROJECT_ROOT"

case "$ACTION" in
  backup)
    TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
    OUT_FILE="$BACKUP_DIR/backup_${TIMESTAMP}.sql"
    docker compose exec -T db sh -c 'exec mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > "$OUT_FILE"
    echo "Backup saved: $OUT_FILE"
    ;;
  restore)
    if [[ $# -ne 2 ]]; then
      usage
      exit 1
    fi
    IN_FILE="$2"
    if [[ ! -f "$IN_FILE" ]]; then
      echo "File not found: $IN_FILE" >&2
      exit 1
    fi
    docker compose exec -T db sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < "$IN_FILE"
    echo "Restore completed from: $IN_FILE"
    ;;
  *)
    usage
    exit 1
    ;;
esac
```

### `zip-project.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$PROJECT_ROOT")"
TIMESTAMP="$(date +%Y%m%d_%H%M)"
ARCHIVE_PATH="$PARENT_DIR/3dprint_${TIMESTAMP}.zip"

cd "$PROJECT_ROOT"

zip -r "$ARCHIVE_PATH" . \
  -x "storage/*" \
  -x "storage/**" \
  -x "bootstrap/cache/*" \
  -x "bootstrap/cache/**" \
  -x "vendor/*" \
  -x "vendor/**" \
  -x "node_modules/*" \
  -x "node_modules/**"

echo "$ARCHIVE_PATH"
```

### `deploy-package.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARENT_DIR="$(dirname "$PROJECT_ROOT")"
TIMESTAMP="$(date +%Y%m%d_%H%M)"
ARCHIVE_PATH="$PARENT_DIR/3dprint_deploy_${TIMESTAMP}.zip"

cd "$PROJECT_ROOT"

zip -r "$ARCHIVE_PATH" . \
  -x ".git/*" \
  -x ".git/**" \
  -x ".idea/*" \
  -x ".idea/**" \
  -x ".github/*" \
  -x ".github/**" \
  -x ".env" \
  -x ".env.*" \
  -x "vendor/*" \
  -x "vendor/**" \
  -x "node_modules/*" \
  -x "node_modules/**" \
  -x "storage/*" \
  -x "storage/**" \
  -x "public/storage/*" \
  -x "public/storage/**" \
  -x "bootstrap/cache/*" \
  -x "bootstrap/cache/**" \
  -x "backups/*" \
  -x "backups/**" \
  -x "docker/*" \
  -x "docker/**" \
  -x "tests/*" \
  -x "tests/**" \
  -x "database/database.sqlite" \
  -x ".phpunit.result.cache" \
  -x "*.log" \
  -x "commands-log.md" \
  -x "plan.md" \
  -x "continuation-notes.txt" \
  -x "howto.md" \
  -x "poradnik-laravel.md" \
  -x "README.md" \
  -x "AGENTS.md" \
  -x "CLAUDE.md" \
  -x "zip-project.sh"

echo "Prepared deployment archive: $ARCHIVE_PATH"
```

---

## Konfiguracja projektu

### `composer.json`

```json
{
    "$schema": "https://getcomposer.org/schema.json",
    "name": "laravel/laravel",
    "type": "project",
    "description": "The skeleton application for the Laravel framework.",
    "keywords": ["laravel", "framework"],
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "filament/filament": "^5.7",
        "laravel/framework": "^13.17",
        "laravel/tinker": "^3.0"
    },
    "require-dev": {
        "fakerphp/faker": "^1.23",
        "laravel/pail": "^1.2.5",
        "laravel/pao": "^1.0.6",
        "laravel/pint": "^1.27",
        "mockery/mockery": "^1.6",
        "nunomaduro/collision": "^8.6",
        "phpunit/phpunit": "^12.5.12"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "scripts": {
        "setup": [
            "composer install",
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
            "@php artisan key:generate",
            "@php artisan migrate --force",
            "npm install --ignore-scripts",
            "npm run build"
        ],
        "dev": [
            "Composer\\Config::disableProcessTimeout",
            "@php artisan dev"
        ],
        "test": [
            "@php artisan config:clear --ansi @no_additional_args",
            "@php artisan test"
        ],
        "post-autoload-dump": [
            "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
            "@php artisan package:discover --ansi",
            "@php artisan filament:upgrade"
        ],
        "post-update-cmd": [
            "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
        ],
        "post-root-package-install": [
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
        ],
        "post-create-project-cmd": [
            "@php artisan key:generate --ansi",
            "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
            "@php artisan migrate --graceful --ansi"
        ],
        "pre-package-uninstall": [
            "Illuminate\\Foundation\\ComposerScripts::prePackageUninstall"
        ]
    },
    "extra": {
        "laravel": {
            "dont-discover": []
        }
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true,
            "php-http/discovery": true
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

### `package.json`

```json
{
    "$schema": "https://www.schemastore.org/package.json",
    "private": true,
    "type": "module",
    "scripts": {
        "build": "vite build",
        "dev": "vite"
    },
    "devDependencies": {
        "@tailwindcss/vite": "^4.0.0",
        "concurrently": "^10.0.3",
        "laravel-vite-plugin": "^3.1",
        "tailwindcss": "^4.0.0",
        "vite": "^8.0.0"
    },
    "optionalDependencies": {
        "@laravel/multiplex": "^0.4.1"
    },
    "dependencies": {
        "glightbox": "^3.3.1"
    }
}
```

### `vite.config.js`

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
```

### `phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="BROADCAST_CONNECTION" value="null"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="DB_URL" value=""/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="PULSE_ENABLED" value="false"/>
        <env name="TELESCOPE_ENABLED" value="false"/>
        <env name="NIGHTWATCH_ENABLED" value="false"/>
    </php>
</phpunit>
```

### `.env.example`

```ini
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

# PHP_CLI_SERVER_WORKERS=4

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
# DB_PASSWORD=

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync

CACHE_STORE=database
# CACHE_PREFIX=

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Dev: Mailpit catches all outgoing mail (UI: http://localhost:8025)
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@3dprint.local"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"
```

### `.env.docker.example`

```ini
APP_NAME=3dprint
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://3dprint.local:8080
TMPDIR=/var/www/html/storage/tmp

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=3dprint
DB_USERNAME=3dprint
DB_PASSWORD=3dprint
```

### `.gitignore`

```gitignore
*.log
.DS_Store
.env
.env.backup
.env.production
.phpactor.json
.phpunit.result.cache
/.codex
/.cursor/
/.idea
/.nova
/.phpunit.cache
/.vscode
/.zed
/auth.json
/node_modules
/public/build
/public/fonts-manifest.dev.json
/public/hot
/public/storage
/storage/*.key
/storage/pail
/vendor
_ide_helper.php
Homestead.json
Homestead.yaml
Thumbs.db
```

---

## Bootstrap i providery

### `bootstrap/app.php`

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
```

### `bootstrap/providers.php`

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
];
```

### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use App\Models\ProjectFile;
use App\Models\ProjectImage;
use App\Observers\ProjectFileObserver;
use App\Observers\ProjectImageObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ProjectImage::observe(ProjectImageObserver::class);
        ProjectFile::observe(ProjectFileObserver::class);
    }
}
```

### `app/Providers/Filament/AdminPanelProvider.php`

```php
<?php

namespace App\Providers\Filament;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->profile()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
                EmailAuthentication::make(),
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

---

## Modele Eloquent

### `app/Models/Project.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'short_description',
        'description',
        'print_notes',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(ProjectImage::class)->orderBy('sort_order');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class)->orderBy('sort_order');
    }

    public function featuredImage(): HasOne
    {
        return $this->hasOne(ProjectImage::class)->where('is_featured', true);
    }
}
```

### `app/Models/ProjectImage.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use App\Support\ProjectMedia;

class ProjectImage extends Model
{
    protected $fillable = [
        'project_id',
        'path_large',
        'path_thumb',
        'alt',
        'is_featured',
        'sort_order',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
    ];

    public function setPathLargeAttribute(mixed $value): void
    {
        if ($value instanceof UploadedFile) {
            $paths = ProjectMedia::storeImage($value);
            $this->attributes['path_large'] = $paths['path_large'];
            $this->attributes['path_thumb'] = $paths['path_thumb'];

            return;
        }

        $this->attributes['path_large'] = $value;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
```

### `app/Models/ProjectFile.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ProjectFile extends Model
{
    protected $fillable = [
        'project_id',
        'label',
        'file_type',
        'path',
        'sort_order',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
```

### `app/Models/User.php`

```php
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Every account in this app is an admin account; tighten this
     * (e.g. an is_admin flag) if non-admin users are ever introduced.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'has_email_authentication' => 'boolean',
        ];
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    public function hasEmailAuthentication(): bool
    {
        return (bool) $this->has_email_authentication;
    }

    public function toggleEmailAuthentication(bool $condition): void
    {
        $this->has_email_authentication = $condition;
        $this->save();
    }
}
```

---

## Observery i helper mediów

### `app/Observers/ProjectImageObserver.php`

```php
<?php

namespace App\Observers;

use App\Models\ProjectImage;
use App\Support\ProjectMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProjectImageObserver
{
    public function creating(ProjectImage $projectImage): void
    {
        if (is_string($projectImage->path_large) && filled($projectImage->path_large) && blank($projectImage->path_thumb)) {
            $projectImage->path_thumb = ProjectMedia::makeThumb($projectImage->path_large);
        }

        if ($projectImage->is_featured) {
            ProjectImage::query()
                ->where('project_id', $projectImage->project_id)
                ->update(['is_featured' => false]);
        }
    }

    public function updating(ProjectImage $projectImage): void
    {
        if ($projectImage->isDirty('path_large') && is_string($projectImage->path_large) && filled($projectImage->path_large)) {
            Storage::disk('public')->delete(array_filter([
                $projectImage->getOriginal('path_large'),
                $projectImage->getOriginal('path_thumb'),
            ]));

            $projectImage->path_thumb = ProjectMedia::makeThumb($projectImage->path_large);
        }

        if ($projectImage->is_featured) {
            ProjectImage::query()
                ->where('project_id', $projectImage->project_id)
                ->whereKeyNot($projectImage->getKey())
                ->update(['is_featured' => false]);
        }
    }

    public function deleting(ProjectImage $projectImage): void
    {
        Storage::disk('public')->delete([
            $projectImage->path_large,
            $projectImage->path_thumb,
        ]);
    }
}
```

### `app/Observers/ProjectFileObserver.php`

```php
<?php

namespace App\Observers;

use App\Models\ProjectFile;
use App\Support\ProjectMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProjectFileObserver
{
    public function creating(ProjectFile $projectFile): void
    {
        if ($projectFile->path instanceof UploadedFile) {
            $projectFile->path = ProjectMedia::storeFile($projectFile->path);
        }
    }

    public function deleting(ProjectFile $projectFile): void
    {
        Storage::disk('public')->delete($projectFile->path);
    }
}
```

### `app/Support/ProjectMedia.php`

```php
<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectMedia
{
    public static function storeImage(UploadedFile $file): array
    {
        $base = 'projects/images/' . Str::uuid()->toString();

        $largePath = $base . '-large.webp';
        $thumbPath = $base . '-thumb.webp';

        Storage::disk('public')->putFileAs('projects/images', $file, basename($largePath));
        Storage::disk('public')->copy($largePath, $thumbPath);

        return [
            'path_large' => $largePath,
            'path_thumb' => $thumbPath,
        ];
    }

    public static function makeThumb(string $largePath): string
    {
        $thumbPath = preg_replace('/(\.[^.\/]+)?$/', '-thumb$1', $largePath, 1);

        if ($thumbPath === $largePath || $thumbPath === null) {
            $thumbPath = $largePath . '-thumb';
        }

        Storage::disk('public')->delete($thumbPath);
        Storage::disk('public')->copy($largePath, $thumbPath);

        return $thumbPath;
    }

    public static function storeFile(UploadedFile $file): string
    {
        $base = 'projects/files/' . Str::uuid()->toString();
        $extension = $file->getClientOriginalExtension();
        $path = $base . ($extension ? '.' . $extension : '');

        Storage::disk('public')->putFileAs('projects/files', $file, basename($path));

        return $path;
    }
}
```

---

## Panel Filament

### `app/Filament/Admin/Resources/ProjectResource.php`

```php
<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ProjectResource\Pages;
use App\Models\Project;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables;
use Filament\Tables\Table;
use BackedEnum;
use UnitEnum;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Project details')->schema([
                TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('title')->required()->maxLength(255),
                Textarea::make('short_description')->required()->rows(3),
                Toggle::make('is_published')->default(false),
                DateTimePicker::make('published_at')->visible(fn (Get $get) => (bool) $get('is_published')),
            ])->columns(2),
            Section::make('Description')->schema([
                Textarea::make('description')->required()->rows(14),
            ])->columnSpanFull(),
            Section::make('Print notes')->schema([
                Textarea::make('print_notes')->rows(10),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('title')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('slug')->searchable()->sortable(),
            Tables\Columns\IconColumn::make('is_published')->boolean(),
            Tables\Columns\TextColumn::make('published_at')->dateTime(),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->since(),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ProjectResource\RelationManagers\ImagesRelationManager::class,
            ProjectResource\RelationManagers\FilesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
```

### `app/Filament/Admin/Resources/ProjectResource/Pages/ListProjects.php`

```php
<?php

namespace App\Filament\Admin\Resources\ProjectResource\Pages;

use App\Filament\Admin\Resources\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
```

### `app/Filament/Admin/Resources/ProjectResource/Pages/CreateProject.php`

```php
<?php

namespace App\Filament\Admin\Resources\ProjectResource\Pages;

use App\Filament\Admin\Resources\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;
}
```

### `app/Filament/Admin/Resources/ProjectResource/Pages/EditProject.php`

```php
<?php

namespace App\Filament\Admin\Resources\ProjectResource\Pages;

use App\Filament\Admin\Resources\ProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
```

### `app/Filament/Admin/Resources/ProjectResource/RelationManagers/ImagesRelationManager.php`

```php
<?php

namespace App\Filament\Admin\Resources\ProjectResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables;
use Filament\Tables\Table;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Images';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Group::make([
                Placeholder::make('current_thumb')
                    ->label('Current thumbnail')
                    ->content(fn (?Get $get, ?\App\Models\ProjectImage $record) => $record?->path_thumb ? view('filament.project-image-preview', [
                        'thumb' => '/storage/' . $record->path_thumb . '?v=' . $record->updated_at?->timestamp,
                        'large' => '/storage/' . $record->path_large,
                        'alt' => $record->alt,
                    ]) : 'No image'),
                FileUpload::make('path_large')
                    ->label('Upload new image')
                    ->image()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->maxSize(5120)
                    ->disk('public')
                    ->directory('projects/images')
                    ->formatStateUsing(fn () => [])
                    ->dehydrated(fn ($state): bool => filled($state))
                    ->helperText('Upload to replace the current image and regenerate large/thumb versions.'),
            ])->columnSpanFull(),
            Toggle::make('is_featured')->label('Featured image'),
            TextInput::make('alt')->maxLength(255),
            TextInput::make('sort_order')->numeric()->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table->headerActions([
            CreateAction::make(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ])->columns([
            Tables\Columns\TextColumn::make('alt')->limit(30),
            Tables\Columns\TextColumn::make('sort_order')->sortable(),
        ])->defaultSort('sort_order')
            ->reorderable('sort_order');
    }
}
```

### `app/Filament/Admin/Resources/ProjectResource/RelationManagers/FilesRelationManager.php`

```php
<?php

namespace App\Filament\Admin\Resources\ProjectResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use App\Support\ProjectMedia;
use Illuminate\Http\UploadedFile;

class FilesRelationManager extends RelationManager
{
    protected static string $relationship = 'files';

    protected static ?string $title = 'Files';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->required()->maxLength(255),
            Select::make('file_type')
                ->required()
                ->options([
                    'stl' => 'stl',
                    'freecad' => 'freecad',
                    'py' => 'py',
                    'other' => 'other',
                ]),
            FileUpload::make('path')
                ->required()
                ->saveUploadedFileUsing(function (UploadedFile $file) {
                    return ProjectMedia::storeFile($file);
                })
                ->maxSize(20480),
            TextInput::make('sort_order')->numeric()->default(0),
        ])->columns(2);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($data['path'] instanceof UploadedFile) {
            $data['path'] = ProjectMedia::storeFile($data['path']);
        }

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table->headerActions([
            CreateAction::make(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ])->columns([
            Tables\Columns\TextColumn::make('label')->searchable(),
            Tables\Columns\TextColumn::make('file_type'),
            Tables\Columns\TextColumn::make('sort_order')->sortable(),
        ])->defaultSort('sort_order')
            ->reorderable('sort_order');
    }
}
```

### `resources/views/filament/project-image-preview.blade.php`

```blade
<div class="space-y-2">
    <a href="{{ $large }}" target="_blank" class="inline-block">
        <img src="{{ $thumb }}" alt="{{ $alt ?? '' }}" class="h-32 rounded border object-cover">
    </a>
</div>
```

---

## Routing i kontrolery

### `routes/web.php`

```php
<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);
Route::get('/projekty', [ProjectController::class, 'index'])->name('projects.index');
Route::get('/projekty/{project:slug}', [ProjectController::class, 'show'])->name('projects.show');
```

### `routes/console.php`

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
```

### `app/Http/Controllers/Controller.php`

```php
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    //
}
```

### `app/Http/Controllers/HomeController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Project;

class HomeController extends Controller
{
    public function __invoke()
    {
        return view('home');
    }
}
```

### `app/Http/Controllers/ProjectController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Project;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Project::query()
            ->where('is_published', true)
            ->with(['featuredImage', 'images'])
            ->latest('published_at')
            ->get();

        return view('projects.index', compact('projects'));
    }

    public function show(Project $project)
    {
        abort_unless($project->is_published, 404);

        $project->load(['images', 'files']);

        return view('projects.show', compact('project'));
    }
}
```

---

## Widoki publiczne (Blade)

### `resources/views/layouts/app.blade.php`

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name', 'Laravel'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 text-gray-900">
<div class="min-h-screen">
    <header class="border-b bg-white">
        <div class="mx-auto max-w-6xl px-4 py-4">
            <div class="text-lg font-semibold">3dprint</div>
        </div>
    </header>

    <div class="mx-auto flex max-w-6xl gap-8 px-4 py-8">
        <aside class="w-56 shrink-0">
            <nav class="space-y-2 text-sm">
                <a class="block rounded px-3 py-2 {{ request()->is('/') ? 'bg-amber-100 font-semibold' : 'hover:bg-gray-100' }}" href="/">Home</a>
                <a class="block rounded px-3 py-2 {{ request()->is('projects*') ? 'bg-amber-100 font-semibold' : 'hover:bg-gray-100' }}" href="{{ route('projects.index') }}">Projects</a>
            </nav>
        </aside>

        <main class="min-w-0 flex-1">
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
```

### `resources/views/home.blade.php`

```blade
@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <div class="space-y-6">
        <h1 class="text-3xl font-bold">3D printing projects</h1>
        <p class="max-w-2xl text-gray-700">
            A curated set of 3D printing projects with images, notes, and downloadable files.
        </p>
    </div>
@endsection
```

### `resources/views/projects/index.blade.php`

```blade
@extends('layouts.app')

@section('title', 'Projects')

@section('content')
    <div class="space-y-6">
        <h1 class="text-3xl font-bold">Projects</h1>

        <div class="grid gap-4">
            @forelse ($projects as $project)
                <a href="{{ route('projects.show', $project) }}" class="rounded border bg-white p-4 hover:border-amber-400">
                    @if ($project->featuredImage)
                        <img src="{{ asset('storage/' . $project->featuredImage->path_thumb) }}" alt="{{ $project->title }}" class="mb-3 h-40 w-full rounded object-cover">
                    @elseif ($project->images->isNotEmpty())
                        <img src="{{ asset('storage/' . $project->images->first()->path_thumb) }}" alt="{{ $project->title }}" class="mb-3 h-40 w-full rounded object-cover">
                    @endif
                    <div class="text-lg font-semibold">{{ $project->title }}</div>
                    <div class="mt-1 text-sm text-gray-600">{{ $project->short_description }}</div>
                </a>
            @empty
                <div class="rounded border bg-white p-4 text-gray-600">No published projects yet.</div>
            @endforelse
        </div>
    </div>
@endsection
```

### `resources/views/projects/show.blade.php`

```blade
@extends('layouts.app')

@section('title', $project->title)

@section('content')
    <div class="space-y-6">
        <h1 class="text-3xl font-bold">{{ $project->title }}</h1>
        <p class="text-gray-700">{{ $project->short_description }}</p>

        @if ($project->images->isNotEmpty())
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($project->images as $image)
                    <a
                        href="{{ asset('storage/' . $image->path_large) }}"
                        class="block overflow-hidden rounded border bg-white"
                        data-glightbox="gallery-{{ $project->id }}"
                        data-title="{{ $image->alt }}"
                    >
                        <img
                            src="{{ asset('storage/' . $image->path_thumb) }}"
                            alt="{{ $image->alt }}"
                            class="h-56 w-full object-cover"
                        >
                    </a>
                @endforeach
            </div>
        @endif

        <div class="prose max-w-none">
            {!! nl2br(e($project->description)) !!}
        </div>

        @if ($project->print_notes)
            <section class="space-y-2">
                <h2 class="text-xl font-semibold">Print notes</h2>
                <div class="prose max-w-none">
                    {!! nl2br(e($project->print_notes)) !!}
                </div>
            </section>
        @endif

        @if ($project->files->isNotEmpty())
            <section class="space-y-3">
                <h2 class="text-xl font-semibold">Downloads</h2>
                <div class="grid gap-3">
                    @foreach ($project->files as $file)
                        <a
                            href="{{ asset('storage/' . $file->path) }}"
                            class="rounded border bg-white p-4 hover:border-amber-400"
                            download
                        >
                            <div class="font-medium">{{ $file->label }}</div>
                            <div class="text-sm text-gray-600">{{ strtoupper($file->file_type) }}</div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
```

---

## Assety front

### `resources/css/app.css`

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';

@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';
}
```

### `resources/js/app.js`

```js
import './glightbox';
```

### `resources/js/glightbox.js`

```js
import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.min.css';

// Initializes the lightbox for project gallery links.
document.addEventListener('DOMContentLoaded', () => {
    GLightbox({
        selector: '[data-glightbox]',
    });
});
```

---

## Migracje

### `database/migrations/0001_01_01_000000_create_users_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
```

### `database/migrations/2026_08_29_213304_create_projects_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('short_description');
            $table->longText('description');
            $table->longText('print_notes')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
```

### `database/migrations/2026_08_29_213305_create_project_images_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('path_large');
            $table->string('path_thumb');
            $table->string('alt')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_images');
    }
};
```

### `database/migrations/2026_08_29_213306_create_project_files_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->enum('file_type', ['stl', 'freecad', 'py', 'other']);
            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_files');
    }
};
```

### `database/migrations/2026_08_30_225700_add_is_featured_to_project_images_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: fresh databases already get this column from the create migration.
        if (Schema::hasColumn('project_images', 'is_featured')) {
            return;
        }

        Schema::table('project_images', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('alt');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('project_images', 'is_featured')) {
            return;
        }

        Schema::table('project_images', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });
    }
};
```

### `database/migrations/2026_08_31_202500_add_two_factor_columns_to_users_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
            $table->boolean('has_email_authentication')->default(false)->after('app_authentication_recovery_codes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'app_authentication_secret',
                'app_authentication_recovery_codes',
                'has_email_authentication',
            ]);
        });
    }
};
```

---

## Seeder i fabryki

### `database/seeders/DatabaseSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate([
            'email' => 'admin@3dprint.local',
        ], [
            'name' => 'Admin',
            'password' => 'password',
        ]);

        User::query()->updateOrCreate([
            'email' => 'test@example.com',
        ], [
            'name' => 'Test User',
            'password' => 'password',
        ]);

        Project::updateOrCreate([
            'slug' => 'sample-bracket',
        ], [
            'title' => 'Sample Bracket',
            'short_description' => 'A simple bracket for testing the project layout and gallery.',
            'description' => 'This sample project demonstrates the MVP project page, gallery, and download area.',
            'print_notes' => 'PLA, 0.2 mm layer height, 20% infill.',
            'is_published' => true,
            'published_at' => now(),
        ]);
    }
}
```

### `database/factories/UserFactory.php`

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
```

---

## Testy

### `tests/TestCase.php`

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    //
}
```

### `tests/Feature/AdminAuthTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_page_renders(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_password_reset_request_page_renders(): void
    {
        $this->get('/admin/password-reset/request')->assertOk();
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_authenticated_user_can_open_profile_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/profile')
            ->assertOk();
    }

    public function test_email_authentication_flag_toggles_in_database(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasEmailAuthentication());

        $user->toggleEmailAuthentication(true);

        $this->assertTrue($user->fresh()->hasEmailAuthentication());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'has_email_authentication' => true,
        ]);
    }

    public function test_app_authentication_secret_is_stored_encrypted(): void
    {
        $user = User::factory()->create();

        $user->saveAppAuthenticationSecret('SECRET123');

        $this->assertSame('SECRET123', $user->fresh()->getAppAuthenticationSecret());
        $this->assertNotSame(
            'SECRET123',
            $user->fresh()->getRawOriginal('app_authentication_secret'),
        );
    }
}
```

### `tests/Feature/ExampleTest.php`

```php
<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
```

### `tests/Unit/ExampleTest.php`

```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_that_true_is_true(): void
    {
        $this->assertTrue(true);
    }
}
```

