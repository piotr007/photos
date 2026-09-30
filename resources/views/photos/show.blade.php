@extends('layouts.app')

@section('title', $photo->displayTitle().' – '.$album->title)
@section('meta_description', $photo->caption ?: Str::limit(strip_tags((string) $photo->description), 160) ?: $album->title)

@push('meta')
    <meta property="og:image" content="{{ $photo->url('large') }}">
    @if ($photo->width)
        <meta property="og:image:width" content="{{ $photo->width }}">
        <meta property="og:image:height" content="{{ $photo->height }}">
    @endif
@endpush

@section('content')
    <nav class="mb-6 flex flex-wrap items-center justify-between gap-3 text-sm text-neutral-500" aria-label="Breadcrumb">
        <div>
            <a href="{{ route('albums.index') }}" class="hover:text-neutral-300">Galleries</a>
            <span class="mx-2">/</span>
            <a href="{{ route('albums.show', $album) }}" class="hover:text-neutral-300">{{ $album->title }}</a>
            <span class="mx-2">/</span>
            <span class="text-neutral-300">{{ $photo->displayTitle() }}</span>
        </div>
        @if ($position)
            <span>{{ $position }} / {{ $total }}</span>
        @endif
    </nav>

    {{-- The photo: click opens the lightbox with the large version --}}
    <figure class="rounded-lg bg-neutral-900 ring-1 ring-white/10">
        <a href="{{ $photo->url('large') }}" data-glightbox data-title="{{ $photo->displayTitle() }}" class="block" title="Open full size">
            <img src="{{ $photo->url('large') }}"
                 srcset="{{ $photo->url('medium') }} 1600w, {{ $photo->url('large') }} 2560w"
                 sizes="(min-width: 1280px) 1280px, 100vw"
                 alt="{{ $photo->alt ?: $photo->displayTitle() }}"
                 @if ($photo->width) width="{{ $photo->width }}" height="{{ $photo->height }}" @endif
                 fetchpriority="high"
                 class="mx-auto max-h-[85vh] w-auto max-w-full rounded-lg object-contain">
        </a>
    </figure>

    {{-- Previous / next inside the album --}}
    <div class="mt-4 flex items-center justify-between text-sm">
        @if ($previous)
            <a href="{{ route('photos.show', [$album, $previous]) }}" rel="prev" class="flex items-center gap-2 text-neutral-400 hover:text-white">
                <span aria-hidden="true">←</span> {{ $previous->displayTitle() }}
            </a>
        @else
            <span></span>
        @endif
        @if ($next)
            <a href="{{ route('photos.show', [$album, $next]) }}" rel="next" class="flex items-center gap-2 text-right text-neutral-400 hover:text-white">
                {{ $next->displayTitle() }} <span aria-hidden="true">→</span>
            </a>
        @endif
    </div>

    <div class="mt-10 grid gap-10 lg:grid-cols-3">
        {{-- Story --}}
        <div class="lg:col-span-2">
            <h1 class="text-3xl font-semibold tracking-tight">{{ $photo->displayTitle() }}</h1>
            @if ($photo->caption)
                <p class="mt-3 text-lg text-neutral-300">{{ $photo->caption }}</p>
            @endif
            @if ($photo->description)
                <div class="prose prose-invert mt-6 max-w-none prose-a:text-white">{!! $photo->description !!}</div>
            @endif

            @if ($photo->tags->isNotEmpty())
                <ul class="mt-8 flex flex-wrap gap-2" aria-label="Tags">
                    @foreach ($photo->tags as $tag)
                        <li>
                            <a href="{{ route('tags.show', $tag) }}" class="rounded-full border border-white/15 px-3 py-1 text-xs text-neutral-300 hover:border-white/40 hover:text-white">#{{ $tag->name }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Shooting details --}}
        <aside class="rounded-lg border border-white/10 bg-neutral-900/60 p-5 text-sm">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-neutral-500">Details</h2>
            <dl class="mt-4 divide-y divide-white/5">
                @php($details = collect(['Taken' => $photo->taken_at?->format('j F Y, H:i'), 'Location' => $photo->location])->filter()->merge($photo->exifSummary()))
                @forelse ($details as $label => $value)
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-neutral-500">{{ $label }}</dt>
                        <dd class="text-right text-neutral-200">{{ $value }}</dd>
                    </div>
                @empty
                    <p class="py-2 text-neutral-500">No shooting data available.</p>
                @endforelse
                @if ($photo->width && $photo->height)
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-neutral-500">Dimensions</dt>
                        <dd class="text-right text-neutral-200">{{ $photo->width }} × {{ $photo->height }} px</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4 py-2">
                    <dt class="text-neutral-500">Gallery</dt>
                    <dd class="text-right"><a href="{{ route('albums.show', $album) }}" class="text-neutral-200 underline decoration-neutral-600 hover:text-white">{{ $album->title }}</a></dd>
                </div>
            </dl>
        </aside>
    </div>
@endsection
