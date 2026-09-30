# 06 — Frontend publiczny (routing, Blade, Tailwind 4, Vite 8, GLightbox)

## 1. Routing — `routes/web.php`

| Metoda | URI | Akcja | Nazwa | Uwagi |
|---|---|---|---|---|
| GET | `/` | `HomeController` (invokable) → `view('home')` | — (brak nazwy) | statyczny tekst |
| GET | `/projekty` | `ProjectController@index` | `projects.index` | tylko `is_published`, sort `published_at` desc, eager `featuredImage`,`images` |
| GET | `/projekty/{project:slug}` | `ProjectController@show` | `projects.show` | binding po `slug`; `abort_unless(is_published, 404)`; `load(['images','files'])` |
| GET | `/up` | health check Laravel | — | z `bootstrap/app.php` |
| * | `/admin/**`, `/livewire/**` | Filament/Livewire | — | rejestrowane przez panel |

Brak: paginacji, tras `about/contact`, sitemap, feed, 404 custom view.

## 2. Layout — `resources/views/layouts/app.blade.php`

Struktura (Tailwind utility classes):

```
<html lang="en"> head: <title>@yield('title', config('app.name'))</title> + @vite(['resources/css/app.css','resources/js/app.js'])
<body class="bg-gray-50 text-gray-900">
  <header class="border-b bg-white"> <div class="mx-auto max-w-6xl px-4 py-4"> "3dprint" </div>
  <div class="mx-auto flex max-w-6xl gap-8 px-4 py-8">
     <aside class="w-56 shrink-0"> <nav> Home | Projects (aktywny: bg-amber-100 font-semibold) </nav>
     <main class="min-w-0 flex-1"> @yield('content') </main>
```

- Minimalistyczny nagłówek, **stałe menu po lewej**, kolumna treści — zgodnie z `plan.md` §5.
- Aktywny link: `request()->is('/')` dla Home ✅; `request()->is('projects*')` dla Projects ❌
  (URL to `/projekty`) → **nigdy aktywny**. Poprawka: `request()->routeIs('projects.*')`.
- Brak: meta description/OG, favicon link (jest `public/favicon.ico` domyślnie ładowany przez
  przeglądarkę), stopki, responsywnego menu (na mobile aside zajmuje 14 rem obok treści).
- Kolor akcentu: `amber` (spójny z `Color::Amber` w panelu).

## 3. Widoki

### `home.blade.php`
`@extends('layouts.app')`, `@section('title','Home')`, `h1` „3D printing projects" + akapit.
Placeholder pod hero (plan §5).

### `projects/index.blade.php`
```blade
@forelse ($projects as $project)
  <a href="{{ route('projects.show', $project) }}" class="rounded border bg-white p-4 hover:border-amber-400">
    @if ($project->featuredImage)          <img src="{{ asset('storage/'.$project->featuredImage->path_thumb) }}" class="mb-3 h-40 w-full rounded object-cover">
    @elseif ($project->images->isNotEmpty()) <img src="{{ asset('storage/'.$project->images->first()->path_thumb) }}" ...>
    @endif
    <div class="text-lg font-semibold">{{ $project->title }}</div>
    <div class="mt-1 text-sm text-gray-600">{{ $project->short_description }}</div>
  </a>
@empty  <div>No published projects yet.</div>
@endforelse
```
Grid `grid gap-4` (1 kolumna — brak `sm:grid-cols-*`), miniatura `h-40 object-cover`.

### `projects/show.blade.php`
Sekcje w kolejności: `h1` tytuł → `short_description` → **galeria** (grid `sm:grid-cols-2 lg:grid-cols-3`,
każdy `<a href="large" data-glightbox="gallery-{id}" data-title="{alt}"><img src="thumb" class="h-56 w-full object-cover">`)
→ `description` (`<div class="prose max-w-none">{!! nl2br(e($project->description)) !!}</div>`)
→ `Print notes` (jeśli są) → `Downloads` (lista `<a href="/storage/..." download>` z `label` + `strtoupper(file_type)`).

- Klasa `prose` wymaga pluginu `@tailwindcss/typography` — **nie jest zainstalowany**, więc `prose`
  nic nie robi (tekst bez typografii). W klonie: `npm i -D @tailwindcss/typography` + `@plugin "@tailwindcss/typography";` w `app.css`.
- `nl2br(e())` — bezpieczne dla plain text; przy WYSIWYG zamienić na `{!! $html !!}` (+ ewentualnie purifier).
- `data-glightbox` grupuje zdjęcia jednego projektu w jedną galerię (strzałki prev/next).

## 4. Assety

### `resources/css/app.css` (Tailwind 4 — konfiguracja w CSS)
```css
@import 'tailwindcss';
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';     /* skompilowane widoki -> Tailwind widzi klasy z Blade */
@theme { --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, ...; }
```
- Tailwind 4 automatycznie skanuje pliki projektu (heurystyka), `@source` dodaje widoki paginacji
  z `vendor/` i cache skompilowanych widoków. Uwaga: `app.css` **nie jest ładowany w panelu
  Filamenta** — partial `resources/views/filament/project-image-preview.blade.php` polega na
  klasach utility obecnych w CSS Filamenta (`h-32`, `rounded`, `border`, `object-cover` działają,
  bo Filament je sam używa; bardziej egzotyczne klasy mogą nie zadziałać → wtedy `style=""` lub
  własny plugin CSS panelu).
- Brak własnych komponentów/`@layer` — czyste utility.

### `resources/js/app.js` → `resources/js/glightbox.js`
```js
import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.min.css';
// Initializes the lightbox for project gallery links.
document.addEventListener('DOMContentLoaded', () => { GLightbox({ selector: '[data-glightbox]' }); });
```
- CSS GLightboxa importowany w JS → Vite wyodrębnia do bundla CSS.
- Domyślne opcje (loop off, touchNavigation on, zoomable). Można dodać `loop: true`, `openEffect: 'fade'`.

### `vite.config.js`
`laravel({ input: [css, js], refresh: true, fonts: [bunny('Instrument Sans', { weights: [400,500,600] })] })`,
`tailwindcss()`. Fonty Bunny są pobierane w czasie buildu i serwowane lokalnie (`public/build`),
manifest fontów dev: `public/fonts-manifest.dev.json` (w `.gitignore`).

### Build
`npm run build` → `public/build/manifest.json` + `assets/app-*.css|js`. `@vite()` w layoutcie
czyta manifest; brak = wyjątek `Vite manifest not found at: public/build/manifest.json`.

## 5. Dostępność / SEO / wydajność — stan i sugestie

| Aspekt | Stan | Sugestia dla klonu |
|---|---|---|
| `alt` obrazów | z pola `alt` (może być puste) | wymagaj `alt`/caption w panelu lub fallback na tytuł |
| Lazy loading | brak | `loading="lazy" decoding="async"` na miniaturach |
| Responsive images | brak (`thumb` = large!) | `srcset` z 2–3 rozmiarami po wdrożeniu resize |
| Meta/OG | brak | `@section('meta')` w layoutcie, OG image = featured large |
| Paginacja | brak | `->paginate(12)` + `{{ $projects->links() }}` (widoki paginacji w `@source`) |
| 404 | domyślna strona Laravela | `resources/views/errors/404.blade.php` |
| Cache | brak | `Cache::remember` na listę / HTTP cache nagłówki dla `/storage/*` w nginx |
| i18n | UI hardcoded EN | `lang/en.json` + `__()` jeśli planujesz PL/EN |
