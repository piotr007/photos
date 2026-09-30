@extends('layouts.app')

@section('title', 'About')
@section('meta_description', 'About the photographer and how to get in touch.')

@section('content')
    <div class="prose prose-invert max-w-2xl prose-a:text-white">
        <h1>About</h1>
        <p>
            {{-- Replace this placeholder with your bio. This page is a plain Blade template
                 (resources/views/about.blade.php); an editable "settings" page is a planned next step. --}}
            I am a photographer who cares about the light, the moment and the story behind each frame.
            Every photograph in these galleries comes with its shooting details - camera, lens,
            exposure - because the craft is part of the picture.
        </p>

        <h2>Contact</h2>
        <p>
            For prints, licensing or commissions write to
            <a href="mailto:hello@example.com">hello@example.com</a>.
        </p>
    </div>
@endsection
