{{-- The badge for a line's SalesTaxBucket value on a tax return. --}}
@props(['bucket'])

@switch($bucket)
    @case('collected') <flux:badge color="emerald">{{ __('Collected') }}</flux:badge> @break
    @case('paid') <flux:badge color="amber">{{ __('Paid') }}</flux:badge> @break
    @case('payment') <flux:badge color="blue">{{ __('Payment') }}</flux:badge> @break
    @case('adjustment') <flux:badge color="violet">{{ __('Return adjustment') }}</flux:badge> @break
    @case('opening') <flux:badge color="zinc">{{ __('Opening balance') }}</flux:badge> @break
    @default <flux:badge color="zinc">{{ $bucket }}</flux:badge>
@endswitch
