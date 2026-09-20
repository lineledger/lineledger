@php
    use App\Enums\Country;

    // The switcher only belongs on the project's own sibling deployments, so it
    // renders when — and only when — the request host is one of the hosts in
    // config('app.app_urls'). That makes it self-configuring: a self-hosted
    // instance on its own domain matches neither, and so never offers to send
    // its users to someone else's app. (APP_REGION still picks the marketing
    // site the legal links point at; it deliberately has no say here, or every
    // self-host that set it to get the right legal documents would get the
    // banner along with them.)
    $appUrls = array_filter(array_map('strval', (array) config('app.app_urls', [])));
    $hosts = array_map(
        fn (string $url): string => mb_strtolower((string) parse_url($url, PHP_URL_HOST)),
        $appUrls,
    );

    $region = array_search(mb_strtolower(request()->getHost()), $hosts, true);
    $current = is_string($region) ? Country::tryFrom($region) : null;
    $other = match ($current) {
        Country::Canada => Country::UnitedStates,
        Country::UnitedStates => Country::Canada,
        default => null,
    };
    $otherUrl = $other === null ? '' : (string) ($appUrls[$other->value] ?? '');
@endphp

@if ($current !== null && $other !== null && $otherUrl !== '')
@persist('geo-banner')
    <div
        x-data="geoBanner({ current: @js(mb_strtolower($current->value)), other: @js(mb_strtolower($other->value)), otherUrl: @js($otherUrl) })"
        x-show="show"
        x-cloak
        x-transition.opacity
        class="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-card/95 px-4 py-3 shadow-[0_-4px_20px_-8px_rgba(13,27,62,0.25)] backdrop-blur"
    >
        <div class="mx-auto flex max-w-4xl flex-col items-center gap-3 sm:flex-row sm:justify-between">
            <p class="text-sm text-foreground">
                You&rsquo;re viewing the
                <strong class="font-semibold">{{ $current->flag() }} {{ $current->label() }}</strong>
                site. Want the
                <strong class="font-semibold">{{ $other->flag() }} {{ $other->label() }}</strong>
                version instead?
            </p>
            <div class="flex flex-none items-center gap-2">
                <a
                    :href="switchHref()"
                    @click.prevent="go()"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:opacity-90"
                >
                    Go to {{ $other->label() }}
                </a>
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
