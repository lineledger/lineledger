<?php

use App\Actions\Sales\SaveFormStyle;
use App\Models\Company;
use App\Models\FormStyle;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Form styles')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public bool $f_show_logo = true;

    public string $f_accent_color = '';

    public string $f_footer_message = '';

    public bool $f_is_default = false;

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'f_name', 'f_show_logo', 'f_accent_color', 'f_footer_message', 'f_is_default', 'f_is_active']);
        $this->f_show_logo = true;
        $this->f_is_active = true;
        Flux::modal('form-styles-form')->show();
    }

    public function openEdit(int $id): void
    {
        $s = FormStyle::findOrFail($id);
        $this->editingId = $s->id;
        $this->f_name = $s->name;
        $this->f_show_logo = $s->show_logo;
        $this->f_accent_color = $s->accent_color ?? '';
        $this->f_footer_message = $s->footer_message ?? '';
        $this->f_is_default = $s->is_default;
        $this->f_is_active = $s->is_active;
        Flux::modal('form-styles-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_show_logo' => ['boolean'],
            'f_accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'f_footer_message' => ['nullable', 'string'],
            'f_is_default' => ['boolean'],
            'f_is_active' => ['boolean'],
        ], [
            'f_accent_color.regex' => __('Enter a 6-digit hex colour like #2563eb.'),
        ]);

        $editing = $this->editingId ? FormStyle::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveFormStyle::class)->handle([
            'name' => $validated['f_name'],
            'show_logo' => $validated['f_show_logo'],
            'accent_color' => $validated['f_accent_color'] ?: null,
            'footer_message' => $validated['f_footer_message'] ?: null,
            'is_default' => $validated['f_is_default'],
            'is_active' => $validated['f_is_active'],
        ], $editing);

        Flux::modal('form-styles-form')->close();
        Flux::toast(variant: 'success', text: __('Form style saved.'));
    }

    #[Computed]
    public function styles()
    {
        return FormStyle::query()->orderBy('name')->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Form styles')" :subheading="__('Named invoice templates. Pick a style on each invoice to override your logo, accent colour and footer message.')">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-style-button">{{ __('New style') }}</flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-center">{{ __('Default') }}</th>
                        <th class="px-4 py-2 text-center">{{ __('Accent') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->styles as $s)
                        <tr data-test="style-row" class="@if(! $s->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">{{ $s->name }}</td>
                            <td class="px-4 py-2 text-center">
                                @if ($s->is_default)
                                    <flux:badge color="emerald" size="sm" data-test="style-default-badge">{{ __('Default') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-center">
                                @if ($s->accent_color)
                                    <span class="inline-block size-4 rounded-full border border-border align-middle" style="background: {{ $s->accent_color }};" title="{{ $s->accent_color }}"></span>
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $s->id }})" />
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ __('No form styles yet. Invoices use your invoice settings until you add one.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="form-styles-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit form style') : __('New form style') }}</flux:heading>
            <flux:input wire:model="f_name" :label="__('Name')" required data-test="style-name" />
            <flux:input wire:model="f_accent_color" :label="__('Accent colour')" placeholder="#2563eb" :description="__('Tints the invoice title, table headers and total. Leave blank for the standard look.')" data-test="style-accent-color" />
            <flux:textarea wire:model="f_footer_message" :label="__('Footer message')" rows="2" :description="__('Overrides the footer message from invoice settings.')" data-test="style-footer-message" />
            <flux:switch wire:model="f_show_logo" :label="__('Show logo')" data-test="style-show-logo" />
            <flux:switch wire:model="f_is_default" :label="__('Default style')" :description="__('Used for invoices that don\'t pick a style.')" data-test="style-is-default" />
            <flux:switch wire:model="f_is_active" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="style-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
