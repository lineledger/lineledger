<?php

use App\Models\Company;
use App\Models\InvoiceTemplate;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Invoice templates')] class extends Component {
    use WithPagination;
    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function templates()
    {
        return InvoiceTemplate::query()
            ->withCount('lines')
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->paginate(25);
    }

    public function delete(int $id): void
    {
        $template = InvoiceTemplate::findOrFail($id);
        abort_if($template->company_id !== $this->company->id, 403);

        $template->delete();

        unset($this->templates);
        Flux::toast(variant: 'success', text: __('Template deleted.'));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Invoice templates') }}</flux:heading>
            <flux:subheading>{{ __('Reusable sets of line items to auto-fill new invoices.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('invoice-templates.create', ['company' => $company->slug])" wire:navigate data-test="new-invoice-template-button">
            {{ __('New template') }}
        </flux:button>
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->templates as $template)
            <div class="rounded-lg border border-border p-4" data-test="invoice-template-card">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ route('invoice-templates.edit', ['company' => $company->slug, 'invoiceTemplate' => $template->id]) }}" wire:navigate class="font-medium underline">
                        {{ $template->name }}
                    </a>
                    @if ($template->is_active)
                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-2 flex items-center justify-between gap-2">
                    <span class="text-sm text-muted-foreground">{{ trans_choice(':count line|:count lines', $template->lines_count, ['count' => $template->lines_count]) }}</span>
                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="delete({{ $template->id }})" wire:confirm="{{ __('Delete this template?') }}">
                        {{ __('Delete') }}
                    </flux:button>
                </div>
            </div>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No templates yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Lines') }}</th>
                    <th class="px-4 py-2">{{ __('Status') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->templates as $template)
                    <tr data-test="invoice-template-row" class="hover:bg-muted">
                        <td class="px-4 py-2">
                            <a href="{{ route('invoice-templates.edit', ['company' => $company->slug, 'invoiceTemplate' => $template->id]) }}" wire:navigate class="underline">
                                {{ $template->name }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ $template->lines_count }}</td>
                        <td class="px-4 py-2">
                            @if ($template->is_active)
                                <flux:badge color="green">{{ __('Active') }}</flux:badge>
                            @else
                                <flux:badge color="zinc">{{ __('Inactive') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <flux:button variant="ghost" size="sm" icon="trash" wire:click="delete({{ $template->id }})" wire:confirm="{{ __('Delete this template?') }}" data-test="delete-invoice-template" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">{{ __('No templates yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->templates->links() }}</div>
</section>
