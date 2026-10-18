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

    $siblings = [];
    if ($current !== null) {
        foreach ($appUrls as $code => $url) {
            if ($code === $current->value || $url === '') {
                continue;
            }
            $sibling = Country::tryFrom((string) $code);
            if ($sibling === null) {
                continue;
            }
            $siblings[] = [
                'code' => mb_strtolower($sibling->value),
                'label' => $sibling->label(),
                'flag' => $sibling->flag(),
                'url' => (string) $url,
            ];
        }
    }
@endphp

@if ($current !== null && $siblings !== [])
@persist('geo-banner')
    <div
        x-data="geoBanner({ current: @js(mb_strtolower($current->value)), siblings: @js($siblings) })"
        x-show="show"
        x-cloak
        x-transition.opacity
        class="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-card/95 px-4 py-3 shadow-[0_-4px_20px_-8px_rgba(13,27,62,0.25)] backdrop-blur"
    >
        <div class="mx-auto flex max-w-4xl flex-col items-center gap-3 sm:flex-row sm:justify-between">
            <p class="text-sm text-foreground">
                You&rsquo;re viewing the
                <strong class="font-semibold">{{ $current->flag() }} {{ $current->label() }}</strong>
                site. Prefer a different country version?
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
