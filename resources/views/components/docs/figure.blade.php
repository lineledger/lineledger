@props([
    'src',
    'alt',
    'caption' => null,
])

{{-- A framed documentation screenshot. Keeps every image in the docs visually
     consistent: rounded border that reads in light and dark, subtle shadow,
     lazy loading, and an optional caption. --}}
<figure {{ $attributes->merge(['class' => 'not-prose my-6']) }}>
    <img
        src="{{ $src }}"
        alt="{{ $alt }}"
        loading="lazy"
        class="w-full rounded-lg border border-zinc-200 shadow-sm dark:border-zinc-700"
    />
    @if ($caption)
        <figcaption class="mt-2 text-center text-sm text-zinc-500 dark:text-zinc-400">
            {{ $caption }}
        </figcaption>
    @endif
</figure>
