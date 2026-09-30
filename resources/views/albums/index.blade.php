@extends('layouts.app')

@section('title', 'Galleries')
@section('meta_description', 'All published photo galleries.')

@section('content')
    <div class="mb-8">
        <h1 class="text-3xl font-semibold tracking-tight">Galleries</h1>
        <p class="mt-2 text-neutral-400">{{ $albums->total() }} {{ Str::plural('gallery', $albums->total()) }}</p>
    </div>

    @if ($albums->isEmpty())
        <p class="rounded-lg border border-dashed border-white/10 p-8 text-center text-neutral-500">No published galleries yet.</p>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
            @foreach ($albums as $album)
                @include('partials.album-tile', ['album' => $album])
            @endforeach
        </div>

        <div class="mt-10">
            {{ $albums->links('vendor.pagination.portfolio') }}
        </div>
    @endif
@endsection
