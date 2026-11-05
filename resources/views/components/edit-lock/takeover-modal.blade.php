{{-- Owner/Admin offer to take over an edit dialog someone else holds
     (HoldsEditLock::acquireEditLock). Once per list page:
     <x-edit-lock.takeover-modal :pending="$editLockPendingTakeover" /> --}}
@props(['pending' => null])

<flux:modal name="edit-lock-takeover" class="max-w-md">
    <div class="space-y-6" data-test="edit-lock-takeover-modal">
        <div>
            <flux:heading size="lg">
                {{ $pending ? __(':name is editing this :noun', ['name' => $pending['name'], 'noun' => $pending['noun']]) : __('Someone is editing this record') }}
            </flux:heading>
            <flux:text class="mt-2">{{ __('Your access lets you take over editing. Anything the other person has not saved will be lost.') }}</flux:text>
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="danger" wire:click="takeOverPendingEditLock" data-test="edit-lock-takeover-confirm">{{ __('Take over editing') }}</flux:button>
        </div>
    </div>
</flux:modal>
