@php
    use App\Support\Http\SiblingSites;

    // Under the logo on the signed-out pages of the project's own per-country
    // sites only (APP_URL_CA / _US / _GB; see SiblingSites): which country this
    // site is for and, since accounts don't carry over, the other countries'
    // sites. Unlike the country banner it can't be dismissed.
    $current = SiblingSites::current();
    $others = SiblingSites::others();
@endphp

@if ($current !== null)
    <div class="mb-2 flex flex-col items-center gap-2 text-center" data-test="site-country">
        <span class="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-0.5 text-sm font-medium text-foreground">
            <span aria-hidden="true">{{ $current->flag() }}</span>
            {{ $current->label() }}
        </span>
        @if ($others !== [])
            <p class="text-xs text-muted-foreground">
                {{ __('Each country has its own site with separate accounts, and an account can’t be moved to another country’s site.') }}
                {{ __('Another country?') }}
                @foreach ($others as $site)
                    <a href="{{ SiblingSites::link($site) }}" class="font-medium whitespace-nowrap underline">{{ $site['country']->flag() }} {{ $site['country']->label() }}</a>@if (! $loop->last) · @endif
                @endforeach
            </p>
        @endif
    </div>
@endif
