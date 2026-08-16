@props([
    // Label Cloudflare reports back in analytics so solve rates can be read
    // per form. Defaults to the current route name.
    'action' => null,
])

@php
    $turnstile = app(\App\Services\Security\Turnstile::class);
    $action = $action ?? \Illuminate\Support\Str::slug((string) request()->route()?->getName());
@endphp

@if ($turnstile->enabled())
    <div>
        {{-- Turnstile injects the hidden `cf-turnstile-response` input into this
             container when it renders, so it must sit inside the <form>. --}}
        <div
            data-turnstile
            data-sitekey="{{ $turnstile->siteKey() }}"
            data-action="{{ $action }}"
            class="flex justify-center"
        ></div>

        <flux:error name="{{ \App\Services\Security\Turnstile::RESPONSE_FIELD }}" />
    </div>

    {{-- `data-navigate-once`: Livewire re-executes body scripts on every
         wire:navigate. Without it api.js loads again on each hop ("Turnstile
         already has been loaded"), and this block redefines its own helpers.
         Both only ever need to run once — the listener below, registered on
         `document`, survives navigation and handles every later page. --}}
    <script data-navigate-once nonce="{{ Vite::cspNonce() }}">
        // Explicit rendering (rather than Cloudflare's implicit `.cf-turnstile`
        // auto-scan) because these pages are reached by `wire:navigate`: Livewire
        // swaps the body without a full page load, so api.js only ever runs its
        // one-shot auto-scan on the first page the visitor lands on. Re-rendering
        // on `livewire:navigated` is what makes the widget appear when the user
        // arrives at /register from the login page.
        window.turnstileWidgets = window.turnstileWidgets || [];

        window.renderTurnstileWidgets = function () {
            if (! window.turnstile) {
                return;
            }

            // A wire:navigate away from this page discards the widget's DOM but
            // leaves it registered inside api.js, which then warns "Cannot find
            // Widget …" and leaks the registration. Reap the orphans first.
            window.turnstileWidgets = window.turnstileWidgets.filter(function (widget) {
                if (widget.el.isConnected) {
                    return true;
                }

                try {
                    window.turnstile.remove(widget.id);
                } catch (e) {}

                return false;
            });

            document.querySelectorAll('[data-turnstile]:not([data-turnstile-rendered])').forEach(function (el) {
                el.setAttribute('data-turnstile-rendered', '');

                var id = window.turnstile.render(el, {
                    sitekey: el.dataset.sitekey,
                    action: el.dataset.action,
                    // Flux drives light/dark with a class on <html>, not with
                    // prefers-color-scheme, so 'auto' would mismatch the page.
                    theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
                });

                window.turnstileWidgets.push({ el: el, id: id });
            });
        };

        // api.js calls this once it has loaded; the guard keeps the listener
        // single even though Livewire re-executes this block on each navigation.
        window.onloadTurnstileCallback = window.renderTurnstileWidgets;

        if (! window.turnstileNavigateBound) {
            window.turnstileNavigateBound = true;
            document.addEventListener('livewire:navigated', function () {
                window.renderTurnstileWidgets();
            });
        }

        window.renderTurnstileWidgets();
    </script>

    <script
        src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onloadTurnstileCallback&render=explicit"
        nonce="{{ Vite::cspNonce() }}"
        data-navigate-once
        async
        defer
    ></script>
@endif
