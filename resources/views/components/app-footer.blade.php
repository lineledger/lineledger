@props(['variant' => 'muted', 'compact' => false])
@php
    [$textClass, $linkClass] = $variant === 'brand'
        ? ['text-[#706f6c] dark:text-[#A1A09A]', 'font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433]']
        : ['text-muted-foreground', 'underline underline-offset-4 hover:text-foreground'];
    $version = (string) config('version.app');
    $owner = (string) config('brand.footer.owner');
    $projectLinks = (bool) config('brand.footer.project_links');
@endphp
<footer {{ $attributes->merge(['class' => $textClass]) }}>
    &copy; {{ date('Y') }} {{ $compact || $owner === '' ? '' : $owner.' ' }}&middot;
    @if ($projectLinks)
        <a href="https://github.com/lineledger/lineledger/releases/tag/v{{ $version }}" target="_blank" rel="noopener" class="{{ $linkClass }}" title="{{ __('Release notes') }}">v{{ $version }}</a>
        &middot;
        <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener" class="{{ $linkClass }}">AGPL-3.0</a>
        &middot;
        <a href="https://github.com/lineledger/lineledger" target="_blank" rel="noopener" class="{{ $linkClass }}">Source</a>
        &middot;
        <a href="{{ app(\App\Support\Legal\LegalDocuments::class)->marketingBaseUrl() }}/legal" target="_blank" rel="noopener" class="{{ $linkClass }}">Legal</a>
    @else
        v{{ $version }}
    @endif
</footer>
