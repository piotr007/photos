# AI Handover — kompletna dokumentacja projektu `3dprint` dla agentów AI

> **Cel tego katalogu:** przekazać agentowi AI (lub człowiekowi) *całą* wiedzę o projekcie
> `3dprint.4fm.pl` w takiej formie, aby na jej podstawie można było **zbudować analogiczną
> aplikację — portfolio fotograficzne** — bez dostępu do tego repozytorium.
>
> Dokumentacja jest **samowystarczalna**: zawiera architekturę, decyzje, środowisko, pełny
> schemat bazy, opis każdego pliku, przepływ mediów, katalog pułapek oraz pełny snapshot kodu
> źródłowego kluczowych plików (załącznik `10-kod-zrodlowy.md`).

**Stan na:** 2026-09-30 · commit `fa534d2` (Initial commit) + zmiany niezacommitowane
(`plan.md`, `commands-log.md`, `deploy-package.sh`).
Źródłem prawdy jest zawsze kod w repozytorium — ten katalog to snapshot.

---

## Jak czytać (kolejność zalecana)

| # | Plik | Co zawiera | Kiedy czytać |
|---|------|------------|--------------|
| 0 | `README.md` (ten plik) | indeks, TL;DR, konwencje | zawsze |
| 1 | [`01-przeglad-projektu.md`](01-przeglad-projektu.md) | cel, zakres, stack z wersjami, architektura, decyzje, status, mapa plików, znane błędy | **obowiązkowo** |
| 2 | [`02-srodowisko-docker.md`](02-srodowisko-docker.md) | docker-compose, Dockerfile, nginx, `.env`, runbook, uprawnienia, Node/Vite, Mailpit, backup, pakowanie | przed uruchomieniem czegokolwiek |
| 3 | [`03-model-domeny-i-baza.md`](03-model-domeny-i-baza.md) | ERD, migracje kolumna po kolumnie, modele, relacje, seeder | **obowiązkowo** |
| 4 | [`04-panel-admin-filament.md`](04-panel-admin-filament.md) | Filament 5: panel, auth + 2FA, ProjectResource, relation managers, gotchas API | przed dotykaniem `/admin` |
| 5 | [`05-media-pipeline.md`](05-media-pipeline.md) | krytyczny przepływ upload → storage → observer → thumb → public; limity; braki; rekomendacje | **obowiązkowo** (najbardziej "kruchy" obszar) |
| 6 | [`06-frontend-publiczny.md`](06-frontend-publiczny.md) | routing, kontrolery, Blade, Tailwind 4, Vite 8, GLightbox | przed zmianami w widokach |
| 7 | [`07-testy-i-jakosc.md`](07-testy-i-jakosc.md) | PHPUnit (sqlite :memory:), istniejące testy, Pint, jak uruchamiać | przy każdej zmianie kodu |
| 8 | [`08-blueprint-portfolio-foto.md`](08-blueprint-portfolio-foto.md) | **plan budowy portfolio fotograficznego** na bazie tej architektury: mapowanie encji, schemat, pipeline zdjęć, panel, front, etapy, checklisty, pytania otwarte, prompt startowy | **główny deliverable** dla nowego projektu |
| 9 | [`09-troubleshooting.md`](09-troubleshooting.md) | katalog: objaw → przyczyna → rozwiązanie (wszystko, co realnie wystąpiło + przewidywane) | gdy coś nie działa |
| 10 | [`10-kod-zrodlowy.md`](10-kod-zrodlowy.md) | pełny snapshot kodu kluczowych plików (wygenerowany z repo) | gdy trzeba skopiować wzorzec 1:1 |
| — | `generate-code-appendix.sh` | skrypt regenerujący plik `10` z aktualnego kodu repo | po zmianach w kodzie |

**Minimalna ścieżka dla agenta budującego portfolio foto:** `01` → `03` → `05` → `04` → `08` → (`10` jako źródło wzorców).

---

## TL;DR projektu w 10 zdaniach

1. Monolit **Laravel 13** (PHP 8.3) z publiczną galerią projektów druku 3D (Blade + Tailwind 4) i panelem CMS **Filament 5** pod `/admin`.
2. Cały dev w **Dockerze**: `app` (php-fpm), `web` (nginx :8080), `db` (MySQL 8.4), `mailpit` (:8025). Assety buduje **Vite 8** na hoście (Node ≥ 18).
3. Domena: `Project` 1‑N `ProjectImage` (large + thumb, `is_featured`, `sort_order`) i 1‑N `ProjectFile` (pliki do pobrania: stl/freecad/py/other).
4. Publiczne trasy: `/`, `/projekty`, `/projekty/{slug}` (tylko `is_published=true`, inaczej 404). UI po angielsku, ścieżki URL po polsku.
5. Upload zdjęć robi **sam Filament** (`FileUpload` → dysk `public`, katalog `projects/images`, nazwa ULID); **observery** dopisują `path_thumb`, sprzątają stare pliki, pilnują unikalności `is_featured`.
6. Miniatury to obecnie **zwykłe kopie** pliku large (brak resize/WebP) — zaplanowane 1200×900 large + 300×300 thumb WebP, nie zaimplementowane.
7. Auth jest w 100% Filamentowy: login, reset hasła (Mailpit w dev), profil, **MFA** (TOTP + kody odzyskiwania + kody e‑mail); `User` implementuje `FilamentUser` + 3 kontrakty MFA; każdy user = admin.
8. Seeder jest **idempotentny** (`updateOrCreate`): `admin@3dprint.local` / `password` + `test@example.com` + projekt `sample-bracket`.
9. Testy: PHPUnit na sqlite `:memory:` — 8 testów (auth Filamenta, 2FA, strona główna) przechodzi.
10. Najczęstsze pułapki: uprawnienia root vs `www-data` w `storage/`, `TMPDIR`, kolejka `sync` (powiadomienia Filamenta są `ShouldQueue`), namespaces Filament 5 (`Filament\Schemas\...`), limit uploadu (nginx 1 MB / PHP 2 MB — niepodniesione!).

---

## Powiązane dokumenty w repozytorium (poza tym katalogiem)

| Plik | Język | Rola |
|------|-------|------|
| `AGENTS.md` | EN | skrócony przewodnik dla agentów (ładowany automatycznie jako instrukcja) — spójny z tym katalogiem, ale krótszy |
| `CLAUDE.md` | EN | wskaźnik na `AGENTS.md` |
| `plan.md` | PL | plan projektu, decyzje (§12), status etapów (§11) |
| `commands-log.md` | PL | dziennik wszystkich istotnych komend shell z komentarzem (konwencja: dopisywać!) |
| `continuation-notes.txt` | EN | notatki z 2026‑08‑30 (częściowo nieaktualne — np. wspomina `intervention/image`, którego już nie ma) |
| `poradnik-laravel.md` | PL | 35 KB tutorial: jak Laravel przetwarza żądanie, Eloquent, migracje, seedery, observery, wzorce, S3, konta admin, reset hasła, 2FA — dobre tło teoretyczne |
| `howto.md` | — | pusty placeholder |

---

## Konwencje pracy w projekcie (obowiązują też w projekcie‑klonie)

- **Loguj komendy:** każda istotna komenda shell + jednozdaniowy komentarz → `commands-log.md` (sekcje `## ...`, blok ```bash, opis pod blokiem).
- **Zapisuj decyzje:** `plan.md` §12 (decyzje) i §11 (status etapów: `(zrobione)` / `(w trakcie)`).
- **Seedery idempotentne** (`updateOrCreate`), nigdy `create()` bez klucza.
- **Media tylko przez modele Eloquent** (observery sprzątają pliki) — nie usuwaj rekordów surowym SQL / `DB::table()`.
- **Artisan w kontenerze jako `www-data`:** `docker compose exec -u www-data app php artisan ...` (inaczej root‑owe pliki w `storage/` blokują PHP‑FPM).
- **Filament 5 ≠ przykłady z internetu** (większość jest dla v3): sprawdzaj namespaces w `04-panel-admin-filament.md`.
- Komentarze w JS: krótki komentarz wyjaśniający działanie (wymóg z `plan.md` §2).
- Język: UI publiczne EN, dokumentacja planistyczna PL, kod/identyfikatory EN.

---

## Jak zaktualizować ten katalog

Załącznik `10-kod-zrodlowy.md` jest generowany skryptem (w nagłówku pliku jest komenda). Pozostałe pliki edytuj ręcznie; zmień datę „Stan na” w tym README.
