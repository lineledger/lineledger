@props([
    'attachment',
    'company',
])

{{--
    A file link that opens PDFs / raster images inline in a new tab and forces a
    download for everything else. Shows the icon, filename and size; renders the
    description underneath when present.
--}}
@php
    $inline = $attachment->isInlineViewable();
    $href = route('attachments.download', ['company' => $company->slug, 'attachment' => $attachment->id])
        .($inline ? '?inline=1' : '');
@endphp

<a href="{{ $href }}" @if ($inline) target="_blank" rel="noopener" @endif
    {{ $attributes->merge(['class' => 'group flex min-w-0 items-start gap-2 text-sm hover:underline']) }}>
    <flux:icon :name="$attachment->iconName()" class="mt-0.5 size-4 shrink-0 text-zinc-500" />
    <span class="min-w-0">
        <span class="flex items-center gap-2">
            <span class="truncate">{{ $attachment->original_filename }}</span>
            <flux:text class="shrink-0 text-xs text-zinc-500">{{ $attachment->humanSize() }}</flux:text>
            @if ($inline)
                <flux:icon.arrow-top-right-on-square class="size-3 shrink-0 text-zinc-400 opacity-0 transition group-hover:opacity-100" />
            @endif
        </span>
        @if ($attachment->description)
            <span class="mt-0.5 block truncate text-xs text-zinc-500">{{ $attachment->description }}</span>
        @endif
    </span>
</a>
