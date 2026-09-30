# Plan projektu — Photo Portfolio

> Klon architektury projektu `3dprint` (dokumentacja referencyjna: `docs/reference-3dprint/`)
> dla domeny *fotografia*. Konwencje z 3dprint obowiązują: decyzje w §12, status etapów w §11,
> dziennik komend w `commands-log.md`.

## 1. Cel i zakres MVP

Portfolio fotografa: publiczne galerie (albumy) ze zdjęciami + CMS dla jednego administratora.
**Kluczowy wymóg właściciela:** każde zdjęcie przechowuje szczegółowe informacje (tytuł, podpis,
opis, alt, EXIF, data, lokalizacja, tagi) z możliwością wprowadzenia/edycji w CMS i pełną
widocznością publiczną (lightbox + osobna strona zdjęcia).

Zakres MVP:

- publiczne: strona główna (hero + wyróżnione galerie), lista galerii, strona galerii (masonry +
  lightbox z podpisem/EXIF), strona zdjęcia (wszystkie dane, prev/next), strona tagu, „About”, 404,
- panel: CRUD albumów (RichEditor, auto‑slug, auto‑`published_at`, reorder), zdjęcia w relation
  managerze (pojedynczy upload z pełnym formularzem, **bulk upload**, podmiana pliku, okładka,
  reorder, tagi), CRUD tagów,
- pipeline: 3 wersje WebP (large 2560 / medium 1600 / thumb 600²), auto‑orientacja, strip
  metadanych, odczyt EXIF → kolumny, brak oryginału, sprzątanie plików przez observer,
- auth: login, reset hasła (Mailpit), profil, 2FA (TOTP + kody odzyskiwania + e‑mail) — opcjonalne.

## 2. Stack

Laravel 13 · Filament 5.9 · Livewire 4 · Intervention Image 3 · MySQL 8.4 (Docker) / SQLite (testy)
· Vite 8 · Tailwind 4 + `@tailwindcss/typography` · GLightbox 3 · PHP 8.3 (GD z WebP, exif, intl).

## 3. Model domeny

`Album 1‑N Photo N‑M Tag` — pełny opis kolumn w `README.md` §Domain i migracjach
`database/migrations/2026_09_30_*`. Slug zdjęcia unikalny **w obrębie albumu**
(`unique(album_id, slug)`, scoped route binding).

## 4. Routing publiczny (EN)

| Trasa | Nazwa | Widok |
|---|---|---|
| `/` | `home` | `home` |
| `/galleries` | `albums.index` | `albums/index` (24/stronę) |
| `/galleries/{album:slug}` | `albums.show` | `albums/show` |
| `/galleries/{album:slug}/{photo:slug}` | `photos.show` | `photos/show` |
| `/tags/{tag:slug}` | `tags.show` | `tags/show` (36/stronę) |
| `/about` | `about` | `about` (statyczny Blade) |

## 5. Frontend

Ciemny motyw (`bg-neutral-950`), górna nawigacja (mobile: `<details>`), aktywny link przez
`routeIs()` (view composer w `AppServiceProvider`), `@stack('meta')` + OG image, masonry
`columns-*`, `srcset` medium/large, `width/height` na `<img>`, lazy loading od 4. zdjęcia.
Opis w lightboxie z ukrytego `div.photo-desc-{id}` (podpis, linia EXIF, data · lokalizacja,
link „View details”).

## 6. Panel admina

`AlbumResource` (grupa „Portfolio”), `PhotosRelationManager` (slide‑over), `TagResource`
(ManageRecords). Wzorce z 3dprint zachowane 1:1: `FileUpload::formatStateUsing(fn () => [])` +
`dehydrated(filled)` + `Placeholder` z podglądem (`filament/photo-preview`).
Nowe: `storeFileNamesIn('original_filename')`, akcja `bulkUpload` (`multiple()` → pętla
`create()`), `ToggleColumn is_cover`, bulk action „Add tags”.

## 7. Pipeline mediów

`App\Support\PhotoMedia::process()` — opis w `README.md` §Media pipeline. Zasada EXIF: **wypełnia
tylko puste pola** (ręczne wpisy admina mają priorytet; po przetworzeniu oryginał i EXIF nie
istnieją, więc nie ma „ponownego odczytu”). Błąd dekodowania → usunięcie surowego pliku i
wyjątek (brak osieroconych plików, brak rekordu).

## 8. Testy

41 testów / 220 asercji (`php artisan test`, SQLite `:memory:`, `withoutVite()`):
`AdminAuthTest` (1:1 z 3dprint), `AdminPanelTest` (Livewire: create/edit/bulkUpload/delete w RM,
unikalność slug, tworzenie albumu), `PhotoPipelineTest` (3 WebP, wymiary, orientacja, EXIF,
podmiana, usuwanie, okładka, slugi, plik uszkodzony), `PublicSiteTest`, `SeederTest`,
`Unit/PhotoMediaExifTest`. Fixture EXIF generowany w locie (`tests/Support/ExifJpeg.php`).

## 9. Środowisko

Docker: `photo-app` (php‑fpm 8.3 + GD/WebP + limity 64M/512M/300s), `photo-web` (nginx :8081,
`client_max_body_size 64m`, cache `/storage` i `/build`), `photo-db` (MySQL 8.4 :3307),
`photo-mailpit` (:8026). Bez Dockera: dowolne PHP 8.3 z intl/gd/exif (użyto static‑php „bulk”).

## 10. Deploy

`deploy-package.sh` → ZIP bez dev‑plików (po `npm run build`). Na serwerze: `composer install
--no-dev`, `.env` prod, `migrate --force`, `storage:link`, `optimize`, document root `public/`.
Checklista „Przed produkcją” w `docs/reference-3dprint/08-blueprint-portfolio-foto.md` §9.

## 11. Status etapów (2026‑09‑30)

| Etap | Status |
|---|---|
| 0. Decyzje właściciela (§12) | ✅ zrobione |
| 1. Szkielet Laravel + Docker + zależności | ✅ zrobione |
| 2. Migracje, modele, `PhotoMedia`, `PhotoObserver`, auth 2FA | ✅ zrobione |
| 3. Panel: AlbumResource, PhotosRelationManager (bulk), TagResource | ✅ zrobione |
| 4. Front publiczny (dark, EN): home, galleries, album, photo, tag, about, 404 | ✅ zrobione |
| 5. Testy (41) + Pint | ✅ zrobione |
| 6. Dokumentacja (README, plan, commands-log, AGENTS) | ✅ zrobione |
| 7. Weryfikacja w Dockerze (`docker compose up --build`) | ⏳ do zrobienia na maszynie z Dockerem |
| 8. Ręczny test panelu w przeglądarce (upload 20 MB JPEG, bulk 20 zdjęć) | ⏳ do zrobienia |
| 9. Etap 2 (opcjonalnie): strona „About” z `settings`, formularz kontaktowy, kolejka dla bulk 50+, S3, watermark, kategorie albumów | 💤 nierozpoczęte |

## 12. Decyzje

| # | Decyzja | Wybór | Uzasadnienie |
|---|---|---|---|
| 1 | Encje | `Album` / `Photo` / `Tag` | zgodnie z blueprintem 08 §1 |
| 2 | Język | **UI EN + URL‑e EN** (`/galleries`, `/about`) | decyzja właściciela |
| 3 | Motyw | **ciemny** | decyzja właściciela; typowy dla foto |
| 4 | Oryginały | **nie przechowywać** — tylko 3 WebP (2560/1600/600) | decyzja właściciela; oszczędność miejsca, ochrona przed kopiowaniem |
| 5 | Informacje o zdjęciu | **pełny zestaw**, publicznie widoczny (lightbox + strona zdjęcia) | kluczowy wymóg właściciela |
| 6 | EXIF vs ręczne dane | EXIF wypełnia tylko puste pola | przewidywalność; admin ma ostatnie słowo |
| 7 | Metadane w plikach publicznych | zawsze strip (GD z natury, Imagick `strip: true`) | prywatność (GPS) |
| 8 | Slug zdjęcia | auto z tytułu → nazwy pliku → `photo`; unikalny per album; edytowalny na edit | czytelne URL‑e, brak kolizji między albumami |
| 9 | Okładka | ≤ 1 per album; pierwsze zdjęcie auto‑okładką; fallback pierwsze zdjęcie | UX bulk uploadu |
| 10 | Bulk upload | sync w żądaniu (≤ 50 plików, 300 s) | prostota; kolejka w etapie 2 |
| 11 | Storage | lokalny dysk `public`; ścieżki względne; `config('media.disk')` | gotowość na S3 (`PhotoMedia::localCopy` obsługuje dyski nielokalne) |
| 12 | Driver obrazów | GD (WebP) domyślnie; `MEDIA_DRIVER=imagick` opcjonalnie | GD zawsze w obrazie Dockera; Imagick lepszy resampling/HEIC |
| 13 | Limity | `memory_limit` podnoszony w runtime do `MEDIA_MEMORY_LIMIT` (512M) | fatal na hostingach z 128M |
| 14 | 2FA | opcjonalne per konto (`isRequired: false`) | jak w 3dprint; wymusić na prod wg potrzeb |
| 15 | Seeder | idempotentny; konto admina tylko `local`/`testing` | błąd #7 z 3dprint |
| 16 | Pliki do pobrania / watermark / kategorie / kontakt | poza MVP | pytania §10 blueprintu — domyślnie „nie” |
| 17 | Assety Filamenta | w `.gitignore` (publikowane przez `filament:upgrade` po `composer install`) | domyślne Laravel 13 |
| 18 | Środowisko dev na tym hoście | static‑php 8.3 (bulk) + Composer + nvm/Node 22 w `~/.local`, `~/.nvm`; Docker w repo do uruchomienia gdzie indziej | brak Dockera/sudo na hoście |
