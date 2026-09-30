{{-- Album tile: square cover (thumb 600px) with title overlay. Expects $album with coverPhoto + photos eager loaded. --}}
@php($cover = $album->coverOrFirst())
<a href="{{ route('albums.show', $album) }}" class="group relative block overflow-hidden rounded-lg bg-neutral-900 ring-1 ring-white/10 transition hover:ring-white/30">
    <div class="aspect-square">
        @if ($cover)
            <img src="{{ $cover->url('thumb') }}"
                 alt="{{ $cover->alt ?: $album->title }}"
                 width="600" height="600"
                 loading="lazy" decoding="async"
                 class="size-full object-cover transition duration-500 group-hover:scale-[1.03] group-hover:opacity-90">
        @else
            <div class="flex size-full items-center justify-center text-neutral-600">
                <svg class="size-10" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z"/></svg>
            </div>
        @endif
    </div>
    <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent p-4 pt-12">
        <h3 class="text-base font-semibold leading-tight text-white">{{ $album->title }}</h3>
        <p class="mt-1 text-xs text-neutral-300">
            {{ collect([$album->shot_at?->format('Y'), $album->location, ($album->photos_count ?? $album->photos->count()).' photos'])->filter()->implode(' · ') }}
        </p>
    </div>
</a>
