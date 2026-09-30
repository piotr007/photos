@extends('layouts.app')

@section('title', 'Not found')

@section('content')
    <div class="mx-auto max-w-md py-20 text-center">
        <p class="text-sm font-semibold uppercase tracking-wider text-neutral-500">404</p>
        <h1 class="mt-2 text-3xl font-semibold">This page is not in the frame</h1>
        <p class="mt-4 text-neutral-400">The gallery or photo you are looking for does not exist or is not published.</p>
        <a href="{{ route('albums.index') }}" class="mt-8 inline-block rounded-md bg-white px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-neutral-200">Browse galleries</a>
    </div>
@endsection
