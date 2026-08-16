@php
    use App\Enums\Country;

    // The country this deployment serves: an explicit APP_REGION wins (handy on
    // host-agnostic environments like local dev), otherwise it's derived from
    // the request host (books.lineledger.ca → CA, anything else → US).
    $current = Country::tryFrom(mb_strtoupper((string) config('app.region')))
        ?? Country::fromHost(request()->getHost());
    $other = $current === Country::Canada ? Country::UnitedStates : Country::Canada;
    $otherUrl = config('app.app_urls')[$other->value] ?? '';
@endphp

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
