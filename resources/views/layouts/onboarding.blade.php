<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="flex min-h-svh flex-col items-center gap-6 p-4 md:p-8">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-medium" wire:navigate>
                <x-app-logo-icon class="h-10 w-auto" />
                <span class="sr-only">{{ config('app.name', 'Ledger') }}</span>
            </a>

            <div class="w-full max-w-5xl">
                {{ $slot }}
            </div>

            <x-app-footer class="mt-auto pt-4 text-center text-xs" />
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts(['nonce' => Vite::cspNonce()])
    </body>
</html>
