@php($company = app('current_company'))
@php($portalEmployee = auth('customer')->user())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <title>{{ $title ?? __('Pay portal') }} · {{ $company?->brand_name ?: $company?->name }}</title>
    </head>
    <body class="min-h-svh bg-background antialiased">
        <header class="border-b border-border bg-card">
            <div class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <div class="flex items-center gap-3">
                    {{-- No exists() probe: on object storage that is a network round trip on every
                         page render. logoUrl() already returns null when no logo is set. --}}
                    @if ($company?->logoUrl())
                        <img src="{{ $company->logoUrl() }}" alt="" class="h-9 w-auto max-w-[160px] object-contain" />
                    @else
                        <flux:heading size="lg">{{ $company?->brand_name ?: $company?->name }}</flux:heading>
                    @endif
                </div>

                @if ($portalEmployee)
                    <div class="flex items-center gap-3">
                        <span class="hidden text-sm text-muted-foreground sm:inline">{{ $portalEmployee->display_name }}</span>
                        <form method="POST" action="{{ route('employee-portal.logout', ['company' => $company->slug]) }}">
                            @csrf
                            <flux:button type="submit" size="sm" variant="ghost" icon="arrow-right-start-on-rectangle">{{ __('Sign out') }}</flux:button>
                        </form>
                    </div>
                @endif
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
            {{ $slot }}
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts(['nonce' => Vite::cspNonce()])
    </body>
</html>
