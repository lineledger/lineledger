@props(['columns'])

{{--
    Column show/hide dropdown for the report control bar's slot. The host
    component must use App\Concerns\HasColumnToggles; $columns is its
    columnRegistry() (key => label). keep-open keeps the menu up while the
    user toggles several columns in a row.
--}}
<flux:dropdown align="end">
    <flux:button variant="ghost" icon="view-columns" icon:trailing="chevron-down" data-test="column-picker">{{ __('Columns') }}</flux:button>

    <flux:menu>
        @foreach ($columns as $key => $label)
            <flux:menu.checkbox
                wire:click="toggleColumn('{{ $key }}')"
                :checked="$this->columnVisible($key)"
                keep-open
                data-test="column-toggle-{{ $key }}"
            >{{ $label }}</flux:menu.checkbox>
        @endforeach
    </flux:menu>
</flux:dropdown>
