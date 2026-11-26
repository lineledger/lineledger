@php
    use App\Enums\Country;
    use App\Support\Http\SiblingSites;

    // The switcher only belongs on the project's own sibling deployments, so it
    // renders when — and only when — the request host is one of the hosts in
    // config('app.app_urls'); see SiblingSites.
    $current = SiblingSites::current();
    $siblings = array_map(static fn (array $site): array => [
        'code' => mb_strtolower($site['country']->value),
        'label' => $site['country']->label(),
        'flag' => $site['country']->flag(),
        'url' => $site['url'],
    ], SiblingSites::others());

    // When Cloudflare's country header names a sibling's country, that sibling
    // goes first and the banner says why, even to a visitor who came from the
    // marketing site: an organization belongs on its own country's site. A
    // choice the visitor already made here still wins (see geoBanner).
    $visitor = Country::tryFrom(mb_strtoupper((string) request()->header('CF-IPCountry')));
    $suggested = null;

    foreach ($siblings as $index => $sibling) {
        if ($visitor !== null && $sibling['code'] === mb_strtolower($visitor->value)) {
            $suggested = $sibling;
            array_splice($siblings, $index, 1);
            array_unshift($siblings, $suggested);
            break;
        }
    }
@endphp

@if ($current !== null && $siblings !== [])
@persist('geo-banner')
    <div
        x-data="geoBanner({ current: @js(mb_strtolower($current->value)), siblings: @js($siblings), suggested: @js($suggested['code'] ?? null) })"
        x-show="show"
        x-cloak
        x-transition.opacity
        class="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-card/95 px-4 py-3 shadow-[0_-4px_20px_-8px_rgba(13,27,62,0.25)] backdrop-blur"
    >
        <div class="mx-auto flex max-w-4xl flex-col items-center gap-3 sm:flex-row sm:justify-between">
            <p class="text-sm text-foreground" data-test="geo-banner-text">
                @if ($suggested !== null)
                    {{ __('You’re viewing the :current site. Your location suggests the :suggested site: each organization is created on the site for its country.', [
                        'current' => $current->flag().' '.$current->label(),
                        'suggested' => $suggested['flag'].' '.$suggested['label'],
                    ]) }}
                @else
                    You&rsquo;re viewing the
                    <strong class="font-semibold">{{ $current->flag() }} {{ $current->label() }}</strong>
                    site. Prefer a different country version?
                @endif
            </p>
            <div class="flex flex-none flex-wrap items-center justify-center gap-2">
                <template x-for="sibling in siblings" :key="sibling.code">
                    <a
                        :href="switchHref(sibling)"
                        @click.prevent="go(sibling)"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:opacity-90"
                    >
                        <span x-text="sibling.flag + ' ' + sibling.label"></span>
                    </a>
                </template>
                <button
                    type="button"
                    @click="stay()"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition hover:text-foreground"
                >
                    Stay here
                </button>
            </div>
        </div>
    </div>
@endpersist
@endif
