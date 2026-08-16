<?php

use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\DocumentFolder;
use App\Models\Invoice;
use App\Models\TaxReturn;
use App\Models\VendorCredit;
use App\Services\AttachmentService;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Attachment index')] class extends Component {
    use WithPagination;

    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $editingId = null;

    public string $editDescription = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function editDescription(int $id): void
    {
        $attachment = Attachment::findOrFail($id);

        $this->editingId = $attachment->id;
        $this->editDescription = (string) $attachment->description;

        Flux::modal('attachment-description-modal')->show();
    }

    public function saveDescription(AttachmentService $service): void
    {
        $this->validate(['editDescription' => ['nullable', 'string', 'max:500']]);

        $service->setDescription(Attachment::findOrFail($this->editingId), $this->editDescription);

        $this->editingId = null;

        Flux::modal('attachment-description-modal')->close();

        Flux::toast(variant: 'success', text: __('Description updated.'));
    }

    #[Computed]
    public function attachments()
    {
        return Attachment::query()
            ->where('attachable_type', '!=', (new DocumentFolder)->getMorphClass())
            ->with(['attachable', 'uploadedBy'])
            ->when($this->search !== '', fn ($q) => $q->where('original_filename', 'like', '%'.$this->search.'%'))
            ->latest()
            ->paginate(25);
    }

    /**
     * Resolve an attachment's source record to a human label, a type name, and
     * (where a web detail page exists) a deep link.
     *
     * @return array{type: string, label: string, url: ?string}
     */
    public function source(Attachment $attachment): array
    {
        $slug = $this->company->slug;
        $id = $attachment->attachable_id;
        $model = $attachment->attachable;

        return match ($attachment->attachable_type) {
            Invoice::class => ['type' => __('Invoice'), 'label' => $model?->invoice_no ?? "#{$id}", 'url' => route('invoices.show', ['company' => $slug, 'invoice' => $id])],
            Bill::class => ['type' => __('Bill'), 'label' => $model?->bill_no ?? "#{$id}", 'url' => route('bills.show', ['company' => $slug, 'bill' => $id])],
            CreditMemo::class => ['type' => __('Credit memo'), 'label' => $model?->credit_memo_no ?? "#{$id}", 'url' => route('credit-memos.show', ['company' => $slug, 'credit_memo' => $id])],
            VendorCredit::class => ['type' => __('Vendor credit'), 'label' => $model?->vendor_credit_no ?? "#{$id}", 'url' => route('vendor-credits.show', ['company' => $slug, 'vendor_credit' => $id])],
            Asset::class => ['type' => __('Fixed asset'), 'label' => $model?->name ?? "#{$id}", 'url' => route('assets.show', ['company' => $slug, 'asset' => $id])],
            TaxReturn::class => ['type' => __('Tax return'), 'label' => "#{$id}", 'url' => route('tax-returns.show', ['company' => $slug, 'tax_return' => $id])],
            default => ['type' => class_basename((string) $attachment->attachable_type), 'label' => $model?->display_name ?? "#{$id}", 'url' => null],
        };
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Attachment index') }}</flux:heading>
            <flux:subheading>{{ __('Every file attached to a transaction across this company.') }}</flux:subheading>
        </div>

        <flux:button icon="folder" :href="route('documents.index', ['company' => $company->slug])" wire:navigate>
            {{ __('Repository') }}
        </flux:button>
    </div>

    <div class="mb-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search file name…') }}" icon="magnifying-glass" class="sm:max-w-md" data-test="attachment-search" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('File') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Attached to') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Uploaded by') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Size') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->attachments as $attachment)
                    @php ($src = $this->source($attachment))
                    <tr data-test="attachment-row">
                        <td class="px-4 py-2">
                            <x-attachment-link :attachment="$attachment" :company="$company" />
                        </td>
                        <td class="px-4 py-2">
                            <button type="button" wire:click="editDescription({{ $attachment->id }})"
                                class="group flex items-center gap-1.5 text-left hover:underline" data-test="attachment-description">
                                @if ($attachment->description)
                                    <span class="text-muted-foreground">{{ $attachment->description }}</span>
                                @else
                                    <span class="text-muted-foreground">{{ __('Add description') }}</span>
                                @endif
                                <flux:icon.pencil-square class="size-3 shrink-0 text-muted-foreground opacity-0 transition group-hover:opacity-100" />
                            </button>
                        </td>
                        <td class="px-4 py-2">
                            <span class="text-muted-foreground">{{ $src['type'] }}</span>
                            @if ($src['url'])
                                <a href="{{ $src['url'] }}" wire:navigate class="ml-1 font-mono underline">{{ $src['label'] }}</a>
                            @else
                                <span class="ml-1 font-mono">{{ $src['label'] }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $attachment->uploadedBy?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $attachment->created_at?->toDateString() }}</td>
                        <td class="px-4 py-2 text-right font-mono whitespace-nowrap">{{ $attachment->humanSize() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No attachments found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->attachments->links() }}</div>

    {{-- Description modal --}}
    <flux:modal name="attachment-description-modal" class="max-w-md">
        <form wire:submit="saveDescription" class="space-y-6">
            <flux:heading size="lg">{{ __('Edit description') }}</flux:heading>
            <flux:textarea wire:model="editDescription" :label="__('Description')" rows="3"
                :placeholder="__('Optional note about this file…')" data-test="attachment-description-input" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="attachment-description-submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
