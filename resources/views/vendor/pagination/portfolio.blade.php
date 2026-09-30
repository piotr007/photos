{{-- Minimal dark-theme pagination for the public site. --}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between text-sm">
        @if ($paginator->onFirstPage())
            <span class="rounded-md border border-white/10 px-3 py-2 text-neutral-600">← Previous</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="rounded-md border border-white/15 px-3 py-2 text-neutral-300 hover:border-white/40 hover:text-white">← Previous</a>
        @endif

        <span class="text-neutral-500">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="rounded-md border border-white/15 px-3 py-2 text-neutral-300 hover:border-white/40 hover:text-white">Next →</a>
        @else
            <span class="rounded-md border border-white/10 px-3 py-2 text-neutral-600">Next →</span>
        @endif
    </nav>
@endif
