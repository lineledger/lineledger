@php
    $locales = \App\Support\Locales::options();
    $current = app()->getLocale();
@endphp

<nav aria-label="{{ __('Language') }}" class="flex items-center justify-center gap-2 text-sm" data-test="language-switcher">
    @foreach ($locales as $code => $label)
        @if ($code === $current)
            <span class="font-medium text-foreground" aria-current="true">{{ $label }}</span>
        @else
            <a
                href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                class="text-muted-foreground hover:text-foreground"
                hreflang="{{ str_replace('_', '-', $code) }}"
            >{{ $label }}</a>
        @endif
        @if (! $loop->last)
            <span class="text-muted-foreground/50" aria-hidden="true">·</span>
        @endif
    @endforeach
</nav>
