@extends('layouts.app')

@section('title', '#'.$tag->name)
@section('meta_description', 'Photographs tagged '.$tag->name.'.')

@section('content')
    <nav class="mb-6 text-sm text-neutral-500" aria-label="Breadcrumb">
        <a href="{{ route('albums.index') }}" class="hover:text-neutral-300">Galleries</a>
        <span class="mx-2">/</span>
        <span class="text-neutral-300">Tag</span>
    </nav>

    <div class="mb-8">
        <h1 class="text-3xl font-semibold tracking-tight">#{{ $tag->name }}</h1>
        <p class="mt-2 text-neutral-400">{{ $photos->total() }} {{ Str::plural('photo', $photos->total()) }}</p>
    </div>

    @if ($photos->isEmpty())
        <p class="rounded-lg border border-dashed border-white/10 p-8 text-center text-neutral-500">No published photos with this tag yet.</p>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
            @foreach ($photos as $photo)
                <a href="{{ route('photos.show', [$photo->album, $photo]) }}" class="group relative block overflow-hidden rounded-lg bg-neutral-900 ring-1 ring-white/10 hover:ring-white/30">
                    <img src="{{ $photo->url('thumb') }}"
                         alt="{{ $photo->alt ?: $photo->displayTitle() }}"
                         width="600" height="600"
                         loading="lazy" decoding="async"
                         class="aspect-square w-full object-cover transition duration-500 group-hover:scale-[1.03]">
                    <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-3 pt-10">
                        <p class="truncate text-sm font-medium text-white">{{ $photo->displayTitle() }}</p>
                        <p class="truncate text-xs text-neutral-300">{{ $photo->album->title }}</p>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-10">
            {{ $photos->links('vendor.pagination.portfolio') }}
        </div>
    @endif
@endsection
