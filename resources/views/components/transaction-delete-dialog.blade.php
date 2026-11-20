{{-- The confirmation dialog for Delete on a transaction's show page (see
     App\Livewire\Concerns\DeletesTransaction). Shows what the delete would do:
     the reasons it can't go ahead yet, with links, and what to know first.
     <x-transaction-delete-dialog :preview="$deletionPreview" :document="$invoice" /> --}}
@props(['preview' => null, 'document'])

@php
    $type = \App\Enums\DeletableDocumentType::forModel($document);
    $number = (string) $document->getAttribute($type->numberColumn());
    $state = $preview['state'] ?? null;
    $blockers = $preview['blockers'] ?? [];
    $warnings = $preview['warnings'] ?? [];
@endphp

<flux:modal name="delete-transaction" class="max-w-lg" data-test="delete-transaction-modal">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __('Delete :document :no?', ['document' => $type->noun(), 'no' => $number]) }}</flux:heading>
            <flux:text class="mt-2" data-test="delete-transaction-explanation">
                @if ($state === \App\Enums\DeletedTransactionState::Draft->value)
                    {{ __('This draft never reached your books. Deleting it removes it for good; the audit log records that it was deleted, by whom and when.') }}
                @else
                    {{ __('This removes the :document and its journal entries from your books and every report, as if it was never entered. A record of what was deleted, by whom and when, stays in the audit log and on the Voided/Deleted Transactions report. This can\'t be undone.', ['document' => $type->noun()]) }}
                @endif
            </flux:text>
        </div>

        @if ($blockers !== [])
            <flux:callout variant="danger" icon="x-circle" data-test="delete-transaction-blockers">
                <flux:callout.heading>{{ __('It can\'t be deleted yet') }}</flux:callout.heading>
                <flux:callout.text>
                    <ul class="mt-1 list-disc space-y-1 ps-5">
                        @foreach ($blockers as $blocker)
                            <li>
                                {{ $blocker['message'] }}
                                @if ($blocker['url'])
                                    <a href="{{ $blocker['url'] }}" wire:navigate class="underline">{{ __('Open') }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($warnings !== [])
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="delete-transaction-warnings">
                <flux:callout.text>
                    <ul class="list-disc space-y-1 ps-5">
                        @foreach ($warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </flux:callout.text>
            </flux:callout>
        @endif

        @if ($blockers === [])
            <flux:textarea wire:model="deleteReason" :label="__('Reason (optional)')" :description="__('Kept with the record of the deletion.')" rows="2" data-test="delete-transaction-reason" />
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            @if ($blockers === [])
                <flux:button variant="danger" wire:click="confirmDelete" data-test="delete-transaction-confirm">
                    {{ __('Delete :document', ['document' => $type->noun()]) }}
                </flux:button>
            @endif
        </div>
    </div>
</flux:modal>
