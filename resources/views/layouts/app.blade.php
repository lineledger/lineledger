<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <x-mail-not-configured-banner />
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
