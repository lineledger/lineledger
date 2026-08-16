{{-- Create/edit modal for a report section. Uses the host component's f_* props. --}}
<flux:modal name="section-form" class="max-w-lg">
    <form wire:submit="saveSection" class="space-y-6">
        <flux:heading size="lg">{{ $editingSectionId ? __('Edit section') : __('New section') }}</flux:heading>

        <flux:input wire:model="f_section_name" :label="__('Section name')" required data-test="section-name-input" />

        @if ($editingSectionId)
            <flux:field>
                <flux:label>{{ __('Group') }}</flux:label>
                <flux:text>{{ __($this->anchorLabels()[$f_section_group] ?? $f_section_group) }}</flux:text>
            </flux:field>
        @else
            <flux:select wire:model="f_section_group" :label="__('Group')" required data-test="section-group-select">
                @foreach ($this->anchorLabels() as $key => $label)
                    <flux:select.option :value="$key">{{ __($label) }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button variant="primary" type="submit" data-test="section-save-button">{{ __('Save') }}</flux:button>
        </div>
    </form>
</flux:modal>
