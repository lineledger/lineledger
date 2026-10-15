@props([
    'index',
    'line',
    'options',
    'prefix' => 'lines',
    'primaryAuto' => 0,
    'secondaryAuto' => 0,
    'pickerTest' => 'line-tax',
    'overrideTest' => 'line-tax-override',
])

{{--
    The Tax cell of a purchase line (cheques, expenses, bills, reimbursements,
    the inbox review grid): a multi-select tax picker capped at two codes (the
    line's primary + secondary slot), then one amount input per SELECTED code
    for typing the tax actually charged. No code, no input.

    Each input binds to `{prefix}.{index}.tax_overrides.{codeId}` — keyed by tax
    code, not slot, so an amount follows its code when unticking the first code
    shifts the second into the primary slot. Blank means "use the calculated
    amount", which is the placeholder. See App\Support\Tax\LineTaxOverrides.

    Props:
      index          the line's index in the bound array
      line           the line's state (tax_code_id, secondary_tax_code_id, tax_code_ids)
      options        the TaxCode collection the picker lists
      prefix         the bound array's property name
      primaryAuto    calculated cents for the primary code (placeholder)
      secondaryAuto  calculated cents for the secondary code (placeholder)
      pickerTest     data-test on the picker button
      overrideTest   data-test on each amount input (each also gets data-tax-code)

    The amount inputs live in <x-line-tax-cell.override /> so this file stays a
    short run of tags after the Flux dropdown (a tall pile of markup straight
    after a Flux tag can trip Blade's component-tag compiler).
--}}
@php($selectedTaxIds = $line['tax_code_ids'] ?? [])
<flux:dropdown>
    <flux:button variant="outline" size="sm" icon:trailing="chevron-down" class="w-full justify-between font-normal" data-test="{{ $pickerTest }}">
        <span class="truncate">{{ $options->whereIn('id', $selectedTaxIds)->pluck('code')->implode(', ') ?: __('Select tax') }}</span>
    </flux:button>
    <flux:menu>
        <flux:menu.checkbox.group wire:model.live="{{ $prefix }}.{{ $index }}.tax_code_ids">
            @foreach ($options as $opt)
                <flux:menu.checkbox value="{{ $opt->id }}" :disabled="count($selectedTaxIds) === 2 && ! in_array($opt->id, $selectedTaxIds)" keep-open>{{ $opt->code }}</flux:menu.checkbox>
            @endforeach
        </flux:menu.checkbox.group>
    </flux:menu>
</flux:dropdown>
@foreach ([[$line['tax_code_id'] ?? null, $primaryAuto], [$line['secondary_tax_code_id'] ?? null, $secondaryAuto]] as [$codeId, $autoCents])
    @continue(empty($codeId))
    @php($taxCode = $options->firstWhere('id', $codeId))
    <x-line-tax-cell.override
        model="{{ $prefix }}.{{ $index }}.tax_overrides.{{ $codeId }}"
        :code-id="$codeId"
        :code="$taxCode"
        :auto="(int) $autoCents"
        :test="$overrideTest"
    />
@endforeach
