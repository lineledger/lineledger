<?php

use Illuminate\Support\Facades\Event;

/*
 * Listener discovery registers every class in app/Listeners with a handle()
 * method as "Class@handle". An Event::listen() for the same class adds a
 * second registration, so the listener runs twice for every event. That
 * happened to NotifyNewDeviceLogin, which did its device tracking twice on
 * every login.
 */
it('registers each app listener once per event', function () {
    $twice = collect(Event::getRawListeners())
        ->flatMap(fn (array $listeners, string $event) => collect($listeners)
            ->map(fn (mixed $listener): ?string => match (true) {
                is_array($listener) && is_string($listener[0] ?? null) => $listener[0].'@'.($listener[1] ?? 'handle'),
                is_string($listener) => str_contains($listener, '@') ? $listener : $listener.'@handle',
                default => null,
            })
            ->filter(fn (?string $listener) => $listener !== null && str_starts_with($listener, 'App\\'))
            ->duplicates()
            ->map(fn (string $listener) => "{$event} → {$listener}")
            ->values());

    expect($twice->all())->toBe([]);
});
