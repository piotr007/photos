@extends('layouts.app')

@section('title', $album->title)
@section('meta_description', $album->short_description ?: Str::limit(strip_tags((string) $album->description), 160))

@push('meta')
    @if ($cover = $album->coverOrFirst())
        <meta property="og:image" content="{{ $cover->url('large') }}">
    @endif
@endpush

@section('content')
    <nav class="mb-6 text-sm text-neutral-500" aria-label="Breadcrumb">
        <a href="{{ route('albums.index') }}" class="hover:text-neutral-300">Galleries</a>
        <span class="mx-2">/</span>
        <span class="text-neutral-300">{{ $album->title }}</span>
    </nav>

    <header class="max-w-3xl">
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ $album->title }}</h1>
        @php($meta = collect([$album->shot_at?->format('F Y'), $album->location, $album->photos->count().' '.Str::plural('photo', $album->photos->count())])->filter())
        @if ($meta->isNotEmpty())
            <p class="mt-2 text-sm text-neutral-400">{{ $meta->implode(' · ') }}</p>
        @endif
        @if ($album->short_description)
            <p class="mt-4 text-lg text-neutral-300">{{ $album->short_description }}</p>
        @endif
        @if ($album->description)
            <div class="prose prose-invert mt-4 max-w-none prose-a:text-white">{!! $album->description !!}</div>
        @endif
    </header>

    @if ($album->photos->isEmpty())
        <p class="mt-10 rounded-lg border border-dashed border-white/10 p-8 text-center text-neutral-500">No photos in this gallery yet.</p>
    @else
        {{-- Masonry grid: medium derivative in the grid, large in the lightbox. width/height prevent layout shifts. --}}
        <section class="mt-10 columns-1 gap-4 sm:columns-2 lg:columns-3" aria-label="Photos">
            @foreach ($album->photos as $photo)
                <figure class="group relative mb-4 break-inside-avoid overflow-hidden rounded-lg bg-neutral-900 ring-1 ring-white/10">
                    <a href="{{ $photo->url('large') }}"
                       data-glightbox
                       data-gallery="album-{{ $album->id }}"
                       data-title="{{ $photo->displayTitle() }}"
                       data-description=".photo-desc-{{ $photo->id }}"
                       class="block">
                        <img src="{{ $photo->url('medium') }}"
                             srcset="{{ $photo->url('medium') }} 1600w, {{ $photo->url('large') }} 2560w"
                             sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"
                             alt="{{ $photo->alt ?: $photo->displayTitle() }}"
                             @if ($photo->width) width="{{ $photo->width }}" height="{{ $photo->height }}" @endif
                             loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}" decoding="async"
                             class="w-full transition duration-500 group-hover:opacity-90">
                    </a>

                    {{-- Hover caption + link to the photo page with all details --}}
                    <figcaption class="pointer-events-none absolute inset-x-0 bottom-0 flex items-end justify-between gap-3 bg-gradient-to-t from-black/80 to-transparent p-3 pt-10 opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-white">{{ $photo->displayTitle() }}</p>
                            @if ($photo->exifLine())
                                <p class="truncate text-xs text-neutral-300">{{ $photo->exifLine() }}</p>
                            @endif
                        </div>
                        <a href="{{ route('photos.show', [$album, $photo]) }}" class="pointer-events-auto shrink-0 rounded-md bg-white/15 px-2 py-1 text-xs text-white backdrop-blur hover:bg-white/30">Details</a>
                    </figcaption>

                    {{-- Lightbox description (referenced by data-description). Hidden in the page itself. --}}
                    <div class="photo-desc-{{ $photo->id }} hidden">
                        @if ($photo->caption)
                            <p>{{ $photo->caption }}</p>
                        @endif
                        @if ($photo->exifLine() || $photo->taken_at || $photo->location)
                            <p class="gdesc-meta">{{ collect([$photo->exifLine(), $photo->taken_at?->format('j M Y'), $photo->location])->filter()->implode(' · ') }}</p>
                        @endif
                        <p><a href="{{ route('photos.show', [$album, $photo]) }}">View details →</a></p>
                    </div>
                </figure>
            @endforeach
        </section>
    @endif

    @if ($album->technical_notes)
        <section class="mt-12 max-w-3xl border-t border-white/10 pt-8">
            <h2 class="text-lg font-semibold">Technical notes</h2>
            <div class="prose prose-invert mt-3 max-w-none prose-a:text-white">{!! $album->technical_notes !!}</div>
        </section>
    @endif
@endsection
