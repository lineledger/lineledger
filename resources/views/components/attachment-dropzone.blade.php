@props([
    'model',
    'accept' => '.pdf,image/*,.doc,.docx,.xls,.xlsx',
    'label' => null,
    'description' => null,
])

{{--
    Reusable drag-and-drop upload zone. Stages files into the host Livewire
    component's `$model` property via $wire.uploadMultiple (same idiom as the
    QuickBooks GL importer), so the host's existing "Upload N files" button and
    validation keep working unchanged.

    $wire.uploadMultiple() fires none of the livewire-upload-* events a
    wire:model file input does, so they are dispatched here. That is what
    lets `wire:loading wire:target="<model>"` work while the bytes are in
    flight: the "Uploading…" note below, and any host button that must wait
    for the files (e.g. Reconcile now) via wire:loading.attr="disabled".
--}}
<div
    x-data="{
        dragging: false,
        send(files) {
            if (! files.length) return
            let detail = { id: this.$wire.$id, property: @js($model) }
            let fire = (name) => this.$root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }))
            fire('livewire-upload-start')
            this.$wire.uploadMultiple(@js($model), files, () => fire('livewire-upload-finish'), () => fire('livewire-upload-error'))
        },
    }"
    x-on:dragover.prevent="dragging = true"
    x-on:dragleave.prevent="dragging = false"
    x-on:drop.prevent="dragging = false; send($event.dataTransfer.files)"
    x-on:click="$refs.dzInput.click()"
    :class="dragging ? 'border-indigo-500 bg-indigo-50 dark:border-indigo-400 dark:bg-indigo-950/40' : 'border-zinc-300 dark:border-zinc-600'"
    class="cursor-pointer rounded-lg border-2 border-dashed p-6 text-center transition hover:border-zinc-400 dark:hover:border-zinc-500"
    {{ $attributes }}
>
    <input type="file" multiple accept="{{ $accept }}" x-ref="dzInput" class="hidden"
        x-on:change="send($event.target.files)" />

    <flux:icon.arrow-up-tray class="mx-auto mb-2 size-6 text-zinc-400" />
    <p class="text-sm">{{ $label ?? __('Drag & drop files here, or click to browse') }}</p>
    @if ($description)
        <p class="mt-1 text-xs text-zinc-500">{{ $description }}</p>
    @endif

    <div wire:loading wire:target="{{ $model }}" class="mt-2 text-sm text-zinc-500">{{ __('Uploading…') }}</div>
</div>
