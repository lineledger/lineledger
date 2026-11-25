<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <x-mail-not-configured-banner />
        <x-read-only-banner />
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
