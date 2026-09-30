@extends('layouts.app')

@section('title', 'Home')
@section('meta_description', 'Photography portfolio - curated galleries with the story and shooting details behind every frame.')

@push('meta')
    @if ($hero)
        <meta property="og:image" content="{{ $hero->url('large') }}">
    @endif
@endpush

@section('content')
    {{-- Hero: cover of the first featured gallery (or a random published photo) --}}
    <section class="relative overflow-hidden rounded-2xl bg-neutral-900 ring-1 ring-white/10">
        @if ($hero)
            <img src="{{ $hero->url('large') }}"
                 srcset="{{ $hero->url('medium') }} 1600w, {{ $hero->url('large') }} 2560w"
                 sizes="100vw"
                 alt="{{ $hero->alt ?: $hero->displayTitle() }}"
                 @if ($hero->width) width="{{ $hero->width }}" height="{{ $hero->height }}" @endif
                 fetchpriority="high"
                 class="h-[60vh] min-h-80 w-full object-cover">
            <div class="absolute inset-0 bg-gradient-to-t from-neutral-950 via-neutral-950/30 to-transparent"></div>
        @else
            <div class="h-[40vh] min-h-64"></div>
        @endif

        <div class="absolute inset-x-0 bottom-0 p-6 sm:p-10">
            <h1 class="max-w-3xl text-4xl font-semibold tracking-tight text-white sm:text-6xl">{{ config('app.name') }}</h1>
            <p class="mt-3 max-w-xl text-neutral-300">
                Curated galleries with the story and the shooting details behind every frame.
                @if ($photoCount)
                    <span class="text-neutral-500">{{ $photoCount }} photographs published.</span>
                @endif
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('albums.index') }}" class="rounded-md bg-white px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-neutral-200">Browse galleries</a>
                <a href="{{ route('about') }}" class="rounded-md border border-white/20 px-4 py-2 text-sm font-medium text-white hover:bg-white/10">About</a>
            </div>
            @if ($hero?->album)
                <p class="mt-4 text-xs text-neutral-500">
                    Cover: <a href="{{ route('photos.show', [$hero->album, $hero]) }}" class="underline decoration-neutral-600 hover:text-neutral-300">{{ $hero->displayTitle() }}</a>
                    from <a href="{{ route('albums.show', $hero->album) }}" class="underline decoration-neutral-600 hover:text-neutral-300">{{ $hero->album->title }}</a>
                </p>
            @endif
        </div>
    </section>

    {{-- Featured galleries --}}
    <section class="mt-12">
        <div class="mb-5 flex items-end justify-between">
            <h2 class="text-xl font-semibold">Featured galleries</h2>
            <a href="{{ route('albums.index') }}" class="text-sm text-neutral-400 hover:text-white">All galleries →</a>
        </div>

        @if ($featured->isEmpty())
            <p class="rounded-lg border border-dashed border-white/10 p-8 text-center text-neutral-500">
                No galleries published yet. Create one in the admin panel.
            </p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
                @foreach ($featured as $album)
                    @include('partials.album-tile', ['album' => $album])
                @endforeach
            </div>
        @endif
    </section>
@endsection
