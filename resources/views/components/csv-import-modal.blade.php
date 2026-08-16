@props([
    'name',
    'templateUrl',
    'subtitle' => null,
    'previewRows' => null,
    'rowErrors' => [],
    'summary' => [],
    'creatableCount' => 0,
    'hasFile' => false,
])

{{-- Shared CSV import dialog for the list pages. Pairs with the ImportsCsvList
     trait: wire:model="importFile" and wire:click="previewImport"/"runImport"
     bind to the host Livewire component, while the display state is passed in as
     props (evaluated in that component's scope). --}}
<flux:modal :name="$name" class="max-w-2xl">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Import') }}</flux:heading>
            @if ($subtitle)
                <flux:subheading>{{ $subtitle }}</flux:subheading>
            @endif
        </div>

        @if (isset($help))
            <flux:callout icon="information-circle">
                <flux:callout.text>{{ $help }}</flux:callout.text>
            </flux:callout>
        @endif

        <flux:button
            icon="arrow-down-tray"
            size="sm"
            variant="filled"
            :href="$templateUrl"
            target="_blank"
            data-test="download-template"
        >
            {{ __('Download template') }}
        </flux:button>

        <div>
            <label class="mb-2 block text-sm font-medium">{{ __('CSV file') }}</label>
            <input type="file" wire:model="importFile" accept=".csv,.txt" class="block w-full text-sm" data-test="import-file" />
            <div wire:loading wire:target="importFile" class="mt-2 text-sm text-muted-foreground">{{ __('Uploading…') }}</div>
            @error('importFile')
                <p class="mt-2 text-sm text-rose-600" data-test="import-file-error">{{ $message }}</p>
            @enderror
        </div>

        @if (! empty($rowErrors))
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 dark:border-rose-900 dark:bg-rose-950/40" data-test="import-errors">
                <p class="mb-1 text-sm font-medium text-rose-700 dark:text-rose-300">{{ __('Problems found') }}</p>
                <ul class="list-inside list-disc text-sm text-rose-700 dark:text-rose-300">
                    @foreach ($rowErrors as $error)
                        <li>{{ $error['row'] > 0 ? __('Row :n: ', ['n' => $error['row']]) : '' }}{{ $error['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($previewRows !== null)
            <div data-test="import-preview">
                <p class="mb-2 text-sm text-muted-foreground">
                    {{ __(':create to create · :skip skipped (already exist) · :rows row(s) total', [
                        'create' => $creatableCount,
                        'skip' => $summary['skipped_existing'] ?? 0,
                        'rows' => $summary['rows'] ?? 0,
                    ]) }}
                </p>
                @if (! empty($previewRows))
                    <div class="max-h-64 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-zinc-50 text-left dark:bg-zinc-800">
                                <tr>
                                    <th class="px-3 py-2 font-medium">{{ __('Row') }}</th>
                                    <th class="px-3 py-2 font-medium">{{ __('Name') }}</th>
                                    <th class="px-3 py-2 font-medium">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($previewRows as $row)
                                    <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                        <td class="px-3 py-1.5 text-muted-foreground">{{ $row['row'] }}</td>
                                        <td class="px-3 py-1.5">{{ $row['name'] }}</td>
                                        <td class="px-3 py-1.5">{{ $row['action'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            @if ($previewRows === null)
                <flux:button variant="primary" wire:click="previewImport" :disabled="! $hasFile" data-test="import-preview-button">
                    {{ __('Preview') }}
                </flux:button>
            @else
                <flux:button
                    variant="primary"
                    wire:click="runImport"
                    :disabled="$creatableCount === 0 || ! empty($rowErrors)"
                    data-test="import-submit"
                >
                    {{ __('Import') }}
                </flux:button>
            @endif
        </div>
    </div>
</flux:modal>
