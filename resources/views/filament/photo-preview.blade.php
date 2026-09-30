{{-- Current photo preview inside the admin form (independent from FilePond). Relative /storage URLs work regardless of APP_URL. --}}
@php
    $version = $photo->updated_at?->timestamp;
    $thumb = \Illuminate\Support\Facades\Storage::disk(config('media.disk'))->url($photo->path_thumb).'?v='.$version;
    $large = \Illuminate\Support\Facades\Storage::disk(config('media.disk'))->url($photo->path_large).'?v='.$version;
@endphp
<div style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap">
    <a href="{{ $large }}" target="_blank" rel="noopener" style="display:inline-block;flex:none">
        <img src="{{ $thumb }}" alt="{{ $photo->alt ?? $photo->displayTitle() }}" style="height:9rem;width:9rem;object-fit:cover;border-radius:0.5rem;border:1px solid rgba(128,128,128,.35)">
    </a>
    <dl style="font-size:.85rem;line-height:1.6;margin:0">
        @if ($photo->width && $photo->height)
            <div><dt style="display:inline;opacity:.7">Size:</dt> <dd style="display:inline;margin:0">{{ $photo->width }} × {{ $photo->height }} px</dd></div>
        @endif
        @if ($photo->original_filename)
            <div><dt style="display:inline;opacity:.7">Original file:</dt> <dd style="display:inline;margin:0">{{ $photo->original_filename }}</dd></div>
        @endif
        <div><dt style="display:inline;opacity:.7">Uploaded:</dt> <dd style="display:inline;margin:0">{{ $photo->created_at?->format('Y-m-d H:i') }}</dd></div>
        <div style="margin-top:.35rem"><a href="{{ $large }}" target="_blank" rel="noopener" style="text-decoration:underline">Open large version</a></div>
    </dl>
</div>
