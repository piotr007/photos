# 07 — Testy i jakość

## 1. Konfiguracja — `phpunit.xml`

```xml
<testsuites> Unit: tests/Unit · Feature: tests/Feature </testsuites>
<source><include><directory>app</directory></include></source>
<php>
  APP_ENV=testing · BCRYPT_ROUNDS=4 · CACHE_STORE=array · DB_CONNECTION=sqlite · DB_DATABASE=:memory:
  MAIL_MAILER=array · QUEUE_CONNECTION=sync · SESSION_DRIVER=array · BROADCAST_CONNECTION=null
  PULSE_ENABLED/TELESCOPE_ENABLED/NIGHTWATCH_ENABLED=false
</php>
```

Konsekwencje:

- Testy **nie dotykają MySQL** — migracje muszą działać na sqlite (stąd guard `Schema::hasColumn`
  w migracji `is_featured`; kolumna `enum` na sqlite staje się `varchar` z CHECK — OK).
- `MAIL_MAILER=array` — maile resetu można asercjonować przez `Notification::fake()`.
- `RefreshDatabase` odpala wszystkie migracje na `:memory:` przy każdym teście (szybko).

## 2. Istniejące testy (8, wszystkie przechodzą — stan z `commands-log.md`)

| Plik | Test | Co sprawdza |
|---|---|---|
| `tests/Feature/AdminAuthTest.php` | `test_admin_login_page_renders` | `GET /admin/login` → 200 (strona Filamenta renderuje się; wymaga `intl`, assetów) |
| | `test_password_reset_request_page_renders` | `GET /admin/password-reset/request` → 200 |
| | `test_guest_is_redirected_to_admin_login` | `GET /admin` → redirect |
| | `test_authenticated_user_can_open_profile_page` | `actingAs(User::factory()->create())` → `GET /admin/profile` → 200 (pośrednio: `canAccessPanel`, kontrakty MFA, kolumny 2FA) |
| | `test_email_authentication_flag_toggles_in_database` | `toggleEmailAuthentication(true)` → `has_email_authentication = 1` w DB |
| | `test_app_authentication_secret_is_stored_encrypted` | `saveAppAuthenticationSecret('SECRET123')` → odczyt równy, `getRawOriginal()` różny (cast `encrypted`) |
| `tests/Feature/ExampleTest.php` | `test_the_application_returns_a_successful_response` | `GET /` → 200 (wymaga **zbudowanego Vite manifestu** lub `$this->withoutVite()`!) |
| `tests/Unit/ExampleTest.php` | `test_that_true_is_true` | placeholder |

Uwaga: `ExampleTest` pada bez `public/build/manifest.json` (wyjątek `ViteManifestNotFoundException`).
W klonie dodaj w `tests/TestCase.php`: `protected function setUp(): void { parent::setUp(); $this->withoutVite(); }`.

## 3. Uruchamianie

```bash
docker compose exec -u www-data app php artisan test                 # -u www-data! (kompiluje widoki do storage/framework/views)
docker compose exec -u www-data app php artisan test --filter=AdminAuthTest
docker compose exec -u www-data app php artisan test --compact
docker compose exec app vendor/bin/pint --test                        # styl kodu (Laravel preset); --repair aby naprawić
```

Po uruchomieniu testów jako root: `docker compose exec app sh -c 'php artisan view:clear && chown -R www-data:www-data storage bootstrap/cache'`.
`.phpunit.result.cache` w katalogu projektu bywa root‑owy (jest w `.gitignore`).

## 4. Czego brakuje (i co dodać w klonie)

| Obszar | Propozycja testu |
|---|---|
| Publiczna lista | opublikowany projekt widoczny, nieopublikowany nie; sort po `published_at`; miniatura featured > first |
| Karta projektu | 200 dla published, 404 dla unpublished/nieistniejącego slug; sekcje warunkowe (print notes, downloads) |
| Observer obrazów | `Storage::fake('public')`; `ProjectImage::create(['path_large' => 'projects/images/a.jpg', ...])` → `path_thumb = projects/images/a-thumb.jpg` i plik istnieje; update `path_large` kasuje stare; delete kasuje oba; drugi `is_featured` odznacza pierwszy |
| Observer plików | delete kasuje plik |
| Filament RM | `Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => EditProject::class])->callTableAction(CreateAction::class, data: [...])` (wymaga `filament/filament` testing helpers — dostępne) |
| Seeder | `db:seed` dwa razy → liczba użytkowników/projektów bez zmian |
| Fabryki | `ProjectFactory`, `ProjectImageFactory`, `ProjectFileFactory` (dziś brak) |

## 5. Jakość kodu — obserwacje

- Brak CI (GitHub Actions) — `.github/` zawiera tylko pusty szablon agenta.
- Pint nie był uruchamiany (mieszane style importów, nieużywane `use`).
- Brak typów zwracanych w kontrolerach (`index()`/`show()` bez `: View`).
- Brak `declare(strict_types=1)` (standard Laravel — OK).
- Komentarze: minimalne; kluczowe „dlaczego" jest w `AGENTS.md`, nie w kodzie — w klonie dopisz
  krótkie komentarze przy `formatStateUsing([])`/`dehydrated(filled)` (to nie jest oczywiste).
