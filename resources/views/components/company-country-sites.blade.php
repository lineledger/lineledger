@props(['prominent' => false])

@php
    use App\Support\Companies\NewCompanyCountries;

    // Beside the country picker, only where COMPANY_COUNTRIES leaves countries
    // out: names the countries this site creates organizations in and links the
    // sibling sites (APP_URL_CA / _US / _GB) that host the others. Prominent
    // when the visitor seems to be in one of those other countries.
    $restricted = NewCompanyCountries::restricted();
    $siblings = $restricted ? NewCompanyCountries::siblings() : [];
@endphp

@if ($restricted)
    <div {{ $attributes }}>
        <flux:callout :variant="$prominent ? 'warning' : 'secondary'" icon="information-circle">
            <flux:callout.text>
                {{ __('This site creates organizations in: :countries.', ['countries' => NewCompanyCountries::describe()]) }}
                @if ($siblings !== [])
                    {{ __('An organization in another country is created on that country’s site:') }}
                    @foreach ($siblings as $sibling)
                        <a href="{{ $sibling['url'] }}" class="font-medium underline">{{ $sibling['country']->flag() }} {{ $sibling['country']->label() }} ({{ $sibling['host'] }})</a>@if (! $loop->last), @endif
                    @endforeach
                @endif
            </flux:callout.text>
        </flux:callout>
    </div>
@endif
