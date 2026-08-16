<?php

use App\Enums\CalculatorMode;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component {
    public string $calculatorMode = '';

    public function mount(): void
    {
        $this->calculatorMode = (Auth::user()->calculator_mode ?? CalculatorMode::default())->value;
    }

    public function updateCalculatorMode(): void
    {
        $validated = $this->validate([
            'calculatorMode' => ['required', Rule::enum(CalculatorMode::class)],
        ]);

        $user = Auth::user();
        $user->calculator_mode = $validated['calculatorMode'];
        $user->save();

        Flux::toast(variant: 'success', text: __('Calculator mode updated.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div class="space-y-8">
            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" :label="__('Theme')">
                <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
                <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
                <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
            </flux:radio.group>

            <flux:radio.group
                wire:model.live="calculatorMode"
                @change="$wire.updateCalculatorMode()"
                :label="__('Calculator mode')"
                :description="__('Choose how the sidebar calculator behaves.')"
                data-test="calculator-mode-group"
            >
                @foreach (App\Enums\CalculatorMode::cases() as $mode)
                    <flux:radio :value="$mode->value" :label="$mode->label()" :description="$mode->description()" />
                @endforeach
            </flux:radio.group>
        </div>
    </x-pages::settings.layout>
</section>
