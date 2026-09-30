<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') · {{ config('app.name') }}@else{{ config('app.name') }}@endif</title>
    <meta name="description" content="@yield('meta_description', 'Photography portfolio - galleries, stories and shooting details behind every frame.')">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="@yield('title', config('app.name'))">
    <meta property="og:url" content="{{ url()->current() }}">
    <link rel="canonical" href="{{ url()->current() }}">
    @stack('meta')
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-neutral-950 font-sans text-neutral-100 antialiased selection:bg-white/20">
<div class="flex min-h-screen flex-col">
    <header class="border-b border-white/10">
        <nav class="mx-auto flex max-w-7xl items-center justify-between gap-6 px-4 py-4 sm:px-6" aria-label="Main">
            <a href="{{ route('home') }}" class="text-base font-semibold tracking-wide uppercase">{{ config('app.name') }}</a>

            {{-- Desktop navigation --}}
            <ul class="hidden items-center gap-1 text-sm sm:flex">
                @foreach ($navigation as $item)
                    <li>
                        <a href="{{ $item['url'] }}"
                           @class(['rounded-md px-3 py-2 transition-colors', 'bg-white/10 text-white' => $item['active'], 'text-neutral-400 hover:text-white' => ! $item['active']])
                           @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    </li>
                @endforeach
            </ul>

            {{-- Mobile navigation: native <details>, no JS required --}}
            <details class="relative sm:hidden">
                <summary class="flex cursor-pointer list-none items-center rounded-md p-2 text-neutral-300 hover:bg-white/10" aria-label="Open menu">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                </summary>
                <ul class="absolute right-0 z-20 mt-2 w-48 rounded-lg border border-white/10 bg-neutral-900 p-1 text-sm shadow-xl">
                    @foreach ($navigation as $item)
                        <li><a href="{{ $item['url'] }}" @class(['block rounded-md px-3 py-2', 'bg-white/10 text-white' => $item['active'], 'text-neutral-300 hover:bg-white/5' => ! $item['active']])>{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </details>
        </nav>
    </header>

    <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 sm:py-10">
        @yield('content')
    </main>

    <footer class="border-t border-white/10">
        <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-6 text-xs text-neutral-500 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All photographs are copyrighted; please ask before reuse.</p>
            <p><a href="{{ route('about') }}" class="hover:text-neutral-300">About &amp; contact</a></p>
        </div>
    </footer>
</div>
</body>
</html>
