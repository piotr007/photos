# 01 — Przegląd projektu `3dprint.4fm.pl`

## 1. Czym jest ten projekt

Portfolio projektów druku 3D: publiczna galeria projektów (zdjęcia, opis, uwagi o druku, pliki do
pobrania STL/FreeCAD/Python) + prosty CMS dla jednego administratora. Aplikacja **monolityczna**
(Blade renderuje HTML po stronie serwera; panel admina to Filament oparty o Livewire) — bez
osobnego API i bez SPA.

Zakres MVP (z `plan.md` §1):

- publiczna strona główna + lista projektów + karta projektu,
- panel admina: CRUD projektów, upload wielu zdjęć z kolejnością i zdjęciem wyróżnionym, upload
  plików do pobrania z etykietą i typem, publikacja (`is_published`, `published_at`),
- logowanie admina, reset hasła e‑mailem, 2FA.

## 2. Stack — dokładne wersje (z `composer.lock` / `package-lock.json` / obrazów Docker)

| Warstwa | Technologia | Wersja | Uwagi |
|---|---|---|---|
| Runtime | PHP (obraz `php:8.3-fpm`) | 8.3 | rozszerzenia: `pdo_mysql mbstring zip exif gd intl` (GD z freetype+jpeg, **bez WebP**) |
| Framework | `laravel/framework` | **v13.29.0** (constraint `^13.17`) | struktura Laravel 11+: `bootstrap/app.php`, `bootstrap/providers.php`, brak `app/Http/Kernel.php` |
| Panel admin | `filament/filament` | **v5.7.6** (constraint `^5.7`) | pakiety: actions, forms, infolists, notifications, query-builder, schemas, support, tables, widgets |
| Reaktywność | `livewire/livewire` | v4.4.2 | zależność Filamenta; upload plików = Livewire temporary uploads |
| 2FA TOTP | `pragmarx/google2fa` | v9.1.0 | zależność Filamenta MFA |
| REPL | `laravel/tinker` | v3.0.2 | |
| Baza | MySQL (obraz `mysql:8.4`) | 8.4 | dev w kontenerze `db`; testy na sqlite `:memory:` |
| WWW | nginx (`nginx:1.27-alpine`) | 1.27 | fastcgi → `app:9000` |
| Mail dev | `axllent/mailpit:latest` | — | SMTP `mailpit:1025`, UI `:8025` |
| Build | Vite | 8.2.2 | `laravel-vite-plugin` 3.2.0 |
| CSS | Tailwind CSS | 4.3.3 (`@tailwindcss/vite`) | konfiguracja w CSS (`@theme`), brak `tailwind.config.js` |
| Lightbox | GLightbox | 3.3.1 | jedyna zależność runtime w `package.json` |
| Fonty | Bunny Fonts przez `laravel-vite-plugin/fonts` | — | `Instrument Sans` 400/500/600 |
| Testy | PHPUnit | 12.5.34 | `phpunit.xml`: sqlite memory, mail array, queue sync |
| Lint | Laravel Pint | 1.30.5 | dev‑dep, nieużywany w CI (brak CI) |
| Dev | `laravel/pail`, `laravel/pao`, `collision`, `mockery`, `faker` | — | standard Laravel |
| Node (host) | Node.js | **≥ 18 wymagane** (użyto nvm → 20) | Node 12 wywala Vite 8 |

**Czego NIE ma** (choć notatki/plan wspominają): `intervention/image` (usunięte — brak w
`composer.lock`), Trix (planowany), `spatie/laravel-medialibrary`, S3 (`league/flysystem-aws-s3-v3`),
paginacja, kategorie/tagi, tabela `settings`, CI/CD, Laravel Boost.

## 3. Architektura

```mermaid
flowchart LR
    subgraph Browser
        Pub[Publiczny użytkownik]
        Adm[Administrator]
    end
    subgraph Docker["docker compose (sieć 3dprint-net)"]
        Web[web: nginx :8080<br/>root=/var/www/html/public]
        App[app: php-fpm 8.3<br/>Laravel 13 + Filament 5]
        Db[(db: MySQL 8.4<br/>volume mysql-data)]
        Mail[mailpit :8025<br/>SMTP :1025]
    end
    Host[Host: npm run build → public/build]
    Pub -->|GET / , /projekty, /projekty/slug| Web
    Adm -->|/admin/* Livewire| Web
    Web -->|fastcgi :9000| App
    App --> Db
    App -->|SMTP reset hasła, kody 2FA| Mail
    App -->|Storage::disk('public')<br/>storage/app/public/projects/*| FS[(storage/ na hoście<br/>wolumen ./:/var/www/html)]
    Web -->|/storage/* symlink public/storage| FS
    Host -.-> Web
```

Warstwy w kodzie:

| Warstwa | Katalog / pliki | Technologia |
|---|---|---|
| Routing publiczny | `routes/web.php` | 3 trasy |
| Kontrolery | `app/Http/Controllers/{HomeController,ProjectController}.php` | zwracają widoki Blade |
| Widoki publiczne | `resources/views/{layouts/app,home,projects/index,projects/show}.blade.php` | Blade + klasy Tailwind |
| Panel admina | `app/Filament/Admin/Resources/ProjectResource*` + `app/Providers/Filament/AdminPanelProvider.php` | Filament 5 (Livewire) |
| Domena | `app/Models/{Project,ProjectImage,ProjectFile,User}.php` | Eloquent |
| Efekty uboczne | `app/Observers/{ProjectImageObserver,ProjectFileObserver}.php` (rejestracja w `AppServiceProvider`) | Eloquent events |
| Pomocnicze | `app/Support/ProjectMedia.php` | statyczne helpery Storage |
| Baza | `database/migrations/*`, `database/seeders/DatabaseSeeder.php`, `database/factories/UserFactory.php` | |
| Front assets | `resources/css/app.css`, `resources/js/{app,glightbox}.js`, `vite.config.js` | Vite + Tailwind 4 |
| Infra | `docker-compose.yml`, `docker/php/Dockerfile`, `docker/nginx/default.conf`, `scripts/db.sh`, `zip-project.sh`, `deploy-package.sh` | |

## 4. Kluczowe decyzje projektowe (i dlaczego)

| Decyzja | Wybór | Uzasadnienie / konsekwencje |
|---|---|---|
| Architektura | monolit Laravel, bez API | jedna osoba, mały ruch, prostota deployu na hosting współdzielony (FTP) |
| CMS | Filament 5 | generuje 80% panelu z modeli; wbudowane auth, reset hasła, MFA, upload, reorder |
| Edytor treści | Trix (planowany) — **obecnie zwykłe `Textarea`**, render `nl2br(e())` | w nowym projekcie rozważ wbudowany `RichEditor` Filamenta (TipTap) zamiast Trix |
| Galeria | GLightbox | lekki, vanilla JS, `data-glightbox` na linkach |
| Storage | lokalny dysk `public` + gotowość na S3 | wszystko przez `Storage::disk('public')`, URL przez `asset('storage/...')` |
| Obrazy | tylko pochodne large + thumb, bez oryginału, WebP | **decyzja podjęta, nie zaimplementowana** — dziś thumb = kopia large |
| Kategorie/tagi | brak w MVP | prosta lista |
| Język | UI EN; URL‑e PL (`/projekty`) | mieszanka — w nowym projekcie zdecyduj świadomie |
| Konta | 1 admin, każdy `User` = admin (`canAccessPanel() => true`) | brak ról; zaostrzyć przed dodaniem zwykłych użytkowników |
| 2FA | wbudowane MFA Filamenta (TOTP + recovery + e‑mail), opcjonalne per konto | zero dodatkowych pakietów; `isRequired: true` możliwe na prod |
| Poczta dev | Mailpit | `QUEUE_CONNECTION=sync` konieczne (powiadomienia są `ShouldQueue`) |
| Deploy | ręczny upload ZIP przez FTP | `deploy-package.sh` wyklucza pliki dev; na serwerze potrzebny `composer install`, `storage:link`, `.env` |
| Baza dev | MySQL 8.4 w kontenerze (nie sqlite) | `.env.docker.example`; testy jednak na sqlite → migracje muszą być przenośne (guard `Schema::hasColumn`) |
| Docker | własny compose (bez Sail) | pełna kontrola nad obrazem PHP (intl, gd, TMPDIR, ini) |

## 5. Status realizacji (z `plan.md` §11, stan 2026‑09‑30)

| Etap | Status | Komentarz |
|---|---|---|
| 1. Repo + Docker + uruchomienie | ✅ | |
| 2. Auth/admin + migracje + modele | ✅ | |
| 3. Publiczny layout + strony + nawigacja | ✅ | brak paginacji/SEO; bug aktywnego linku (patrz §8) |
| 4. CMS: CRUD projektów, zdjęć, plików | 🟡 w trakcie | działa; brak WYSIWYG, brak bulk uploadu zdjęć |
| 5. Obróbka obrazów + galeria z powiększeniem | 🟡 w trakcie | GLightbox ✅; resize/WebP ❌ |
| 6. Backup/restore bazy | ✅ | `scripts/db.sh` |
| 7. UX, seed, porządki migracji | 🟡 w trakcie | seed bez zdjęć; migracja `is_featured` z guardem |
| 8. Pakowanie release (ZIP z datą) | ✅ | `zip-project.sh`, `deploy-package.sh` |
| 9. Mailpit + reset hasła + 2FA | ✅ | 8 testów przechodzi |

## 6. Mapa plików (co jest gdzie i po co)

```
3dprint/
├── AGENTS.md, CLAUDE.md              # instrukcje dla agentów (EN)
├── plan.md, commands-log.md          # plan/decyzje, dziennik komend (PL)
├── continuation-notes.txt            # notatki 2026-08-30 (częściowo nieaktualne)
├── poradnik-laravel.md               # tutorial Laravel pod ten projekt (PL)
├── howto.md                          # pusty
├── docs/ai-handover/                 # TEN KATALOG
├── docker-compose.yml                # app, web, db, mailpit
├── docker/php/Dockerfile             # php:8.3-fpm + ext + composer + ini (TMPDIR, logi)
├── docker/nginx/default.conf         # root public/, fastcgi app:9000
├── scripts/db.sh                     # backup|restore MySQL przez docker compose exec
├── zip-project.sh                    # ZIP projektu (bez vendor/node_modules/storage/bootstrap/cache)
├── deploy-package.sh                 # ZIP deploy (dodatkowo bez .git/.env/docs/tests/docker/...)
├── .env.example                      # pełny szablon Laravel + Mailpit (DB=sqlite!)
├── .env.docker.example               # minimalny szablon pod Docker/MySQL (bez sekcji MAIL/QUEUE!)
├── composer.json / composer.lock     # PHP deps
├── package.json / package-lock.json  # Node deps (vite, tailwind, glightbox)
├── vite.config.js                    # laravel-vite-plugin + tailwind + bunny fonts
├── phpunit.xml                       # sqlite :memory:, queue sync, mail array
├── app/
│   ├── Filament/Admin/Resources/ProjectResource.php               # form + table + relations + pages
│   ├── Filament/Admin/Resources/ProjectResource/Pages/{List,Create,Edit}Project.php
│   ├── Filament/Admin/Resources/ProjectResource/RelationManagers/ImagesRelationManager.php
│   ├── Filament/Admin/Resources/ProjectResource/RelationManagers/FilesRelationManager.php
│   ├── Http/Controllers/{Controller,HomeController,ProjectController}.php
│   ├── Models/{Project,ProjectImage,ProjectFile,User}.php
│   ├── Observers/{ProjectImageObserver,ProjectFileObserver}.php
│   ├── Providers/AppServiceProvider.php                            # rejestracja observerów
│   ├── Providers/Filament/AdminPanelProvider.php                   # panel /admin, auth, MFA
│   └── Support/ProjectMedia.php                                    # storeImage/makeThumb/storeFile
├── bootstrap/{app.php,providers.php}
├── config/{app,auth,cache,database,filesystems,logging,mail,queue,services,session}.php  # domyślne Laravel
├── database/
│   ├── migrations/0001_01_01_00000{0,1,2}_create_{users,cache,jobs}_table.php
│   ├── migrations/2026_08_29_21330{4,5,6}_create_{projects,project_images,project_files}_table.php
│   ├── migrations/2026_08_30_225700_add_is_featured_to_project_images_table.php   # guard hasColumn
│   ├── migrations/2026_08_31_202500_add_two_factor_columns_to_users_table.php
│   ├── seeders/DatabaseSeeder.php                                  # idempotentny
│   └── factories/UserFactory.php
├── public/
│   ├── index.php, .htaccess, robots.txt, favicon.ico
│   ├── css|js|fonts/filament/**                                    # opublikowane assety Filamenta (w git)
│   ├── build/                                                      # output Vite (w .gitignore!)
│   └── storage -> ../storage/app/public                            # symlink (w .gitignore, php artisan storage:link)
├── resources/
│   ├── css/app.css                                                 # Tailwind 4: @import, @source, @theme
│   ├── js/app.js → js/glightbox.js                                 # init GLightbox
│   └── views/layouts/app.blade.php, home.blade.php, projects/{index,show}.blade.php,
│       views/filament/project-image-preview.blade.php              # partial podglądu w adminie
├── routes/{web.php,console.php}
├── storage/app/public/projects/{images,files}/                     # media (poza git)
├── storage/tmp/                                                    # TMPDIR/upload_tmp_dir (tworzony w Dockerfile)
└── tests/Feature/{AdminAuthTest,ExampleTest}.php, tests/Unit/ExampleTest.php, tests/TestCase.php
```

## 7. Znane luki / roadmap

1. **Obróbka obrazów** — resize 1200×900 (large), crop 300×300 (thumb), WebP. Wymaga biblioteki
   (Intervention Image v3) i **GD z WebP** (Dockerfile: `libwebp-dev` + `--with-webp`) albo Imagick.
2. **WYSIWYG** — Trix planowany; `description`/`print_notes` to plain textarea, render `nl2br(e())`.
3. **Strona główna** — tylko nagłówek + akapit; hero/ustawienia (`settings`) do zrobienia.
4. **Bulk upload zdjęć** — RM tworzy jeden rekord na formularz; brak `FileUpload::multiple()` z pętlą.
5. **Limity uploadu** — nie podniesione (nginx `client_max_body_size` domyślnie 1 MB, PHP
   `upload_max_filesize` domyślnie 2 MB) — patrz `05-media-pipeline.md` §6.
6. **S3** — abstrakcja `Storage` gotowa, brak konfiguracji dysku/zależności.
7. Brak paginacji, meta SEO/OG, sitemap, CI.

## 8. Znane błędy i niespójności w kodzie (do świadomego przeniesienia lub poprawy)

| # | Miejsce | Problem | Skutek | Sugestia |
|---|---|---|---|---|
| 1 | `resources/views/layouts/app.blade.php` | aktywny link: `request()->is('projects*')`, a URL to `/projekty` | link „Projects" nigdy nie jest podświetlony | `request()->routeIs('projects.*')` |
| 2 | `app/Support/ProjectMedia::storeImage()` | nazywa pliki `-large.webp`/`-thumb.webp`, ale **nie konwertuje** do WebP (kopiuje bajty) | mylące rozszerzenie; metoda używana tylko przez mutator `setPathLargeAttribute` (ścieżka nie‑Filamentowa) | zaimplementować konwersję lub zmienić nazewnictwo |
| 3 | `FilesRelationManager::mutateFormDataBeforeCreate()` | Filament nigdy nie przekazuje tu `UploadedFile` (upload zapisuje `saveUploadedFileUsing`) | martwy kod | usunąć |
| 4 | `ProjectImage::setPathLargeAttribute()` + `ProjectFileObserver::creating()` (gałąź `UploadedFile`) | to samo — ścieżki „na wszelki wypadek" | martwy kod w flow Filamenta | zostawić jako API programistyczne lub usunąć |
| 5 | `HomeController` | nieużywany `use App\Models\Project` | brak | usunąć import |
| 6 | `.env.docker.example` | brak `MAIL_*`, `QUEUE_CONNECTION=sync` (są w `.env.example` i w realnym `.env`) | po `cp .env.docker.example .env` reset hasła nie wysyła maili / kolejka `database` bez workera | uzupełnić szablon |
| 7 | `DatabaseSeeder` | seeduje też `test@example.com`/`password`, a każdy user ma dostęp do `/admin`; `db:seed` na prod nadpisuje hasła | ryzyko bezpieczeństwa | owinąć w `if (app()->isLocal())` |
| 8 | `DatabaseSeeder` używa `WithoutModelEvents` | observery **nie odpalą się** przy seedowaniu obrazów (brak thumb, brak unikalności featured) | dziś bez skutku (seed nie tworzy obrazów) | pamiętać przy seedowaniu zdjęć |
| 9 | `project_files.file_type` = kolumna `enum` MySQL | zmiana listy typów wymaga migracji ALTER; sqlite ignoruje enum | sztywność | w nowym projekcie `string` + walidacja w formularzu lub PHP enum |
| 10 | `ProjectMedia::makeThumb()` | `Storage::copy()` na dysku `local` — brak obsługi błędu, gdy `path_large` nie istnieje | wyjątek 500 w adminie | try/catch + log |
| 11 | Migracja `2026_08_30_225700_add_is_featured...` | duplikat kolumny dodanej już w `create_project_images_table` | historycznie wywalało testy; naprawione guardem `Schema::hasColumn` | w nowym projekcie nie kopiować tej migracji |
| 12 | `AdminPanelProvider` → `FilamentInfoWidget` | dashboard pokazuje widget z linkami do dokumentacji Filamenta | kosmetyka | usunąć na prod |

## 9. Bezpieczeństwo — stan faktyczny

- Panel chroniony middleware `Authenticate` Filamenta; poza `APP_ENV=local` Filament wymaga
  `FilamentUser::canAccessPanel()` — zaimplementowane (zwraca `true`).
- Hasła: cast `'password' => 'hashed'` (bcrypt, `BCRYPT_ROUNDS=12`).
- Sekret TOTP i kody odzyskiwania: casty `encrypted` / `encrypted:array` (klucz `APP_KEY` —
  jego zmiana unieważnia 2FA wszystkich kont!).
- Upload: `FileUpload::image()` (walidacja MIME obrazów) + `maxSize(5120)` KB; pliki: `maxSize(20480)`
  KB bez ograniczenia typu — pliki lądują na dysku **publicznym** (dostępne po URL bez auth).
- Brak rate‑limitingu poza domyślnym Livewire (`throttle:60,1` na upload).
- Public: brak formularzy → brak CSRF do obsługi; XSS: opisy renderowane przez `e()`.
- Sekrety w repo: `.env` w `.gitignore` ✅; `.env.*.example` bez sekretów ✅; seed z hasłem
  `password` — tylko dev.
