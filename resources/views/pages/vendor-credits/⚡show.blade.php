<?php

use App\Enums\VendorCreditStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\VendorCredit;
use App\Services\AttachmentService;
use App\Services\Posting\VendorCreditPoster;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Vendor credit')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public VendorCredit $vendorCredit;

    public array $newAttachments = [];

    public function mount(Company $company, VendorCredit $vendor_credit): void
    {
        $this->company = $company;
        $this->vendorCredit = $vendor_credit->load('lines.account', 'lines.taxCode', 'lines.secondaryTaxCode', 'contact', 'journalEntry');
    }

    public function post(VendorCreditPoster $poster): void
    {
        try {
            $poster->post($this->vendorCredit);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->vendorCredit->refresh();
        Flux::toast(variant: 'success', text: __('Vendor credit posted.'));
    }

    public function void(VendorCreditPoster $poster): void
    {
        try {
            $poster->void($this->vendorCredit);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Vendor credit voided.'));
        $this->redirectRoute('vendor-credits.index', ['company' => $this->company->slug], navigate: true);
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->vendorCredit, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        $service->remove(Attachment::findOrFail($id), $this->vendorCredit);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    #[Computed]
    public function attachments()
    {
        return $this->vendorCredit->attachments()->get();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Vendor credit') }} {{ $vendorCredit->vendor_credit_no }}</flux:heading>
            <flux:subheading>
                {{ $vendorCredit->contact->display_name }} &middot;
                {{ $vendorCredit->vendor_credit_date->toDateString() }}
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($vendorCredit->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($vendorCredit->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $vendorCredit->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($vendorCredit->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($vendorCredit->status === VendorCreditStatus::Draft)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" icon="check-circle" wire:click="post" data-test="post-vendor-credit-button">
                    {{ __('Post') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="vendor-credit-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($vendorCredit->status === VendorCreditStatus::Draft)
                        <flux:menu.item class="lg:hidden" icon="check-circle" wire:click="post" data-test="post-vendor-credit-menu-item">
                            {{ __('Post') }}
                        </flux:menu.item>
                    @endif
                    <flux:menu.item icon="printer" :href="route('vendor-credits.print', ['company' => $company->slug, 'vendor_credit' => $vendorCredit->id])" target="_blank" data-test="print-vendor-credit-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    @if ($vendorCredit->status !== VendorCreditStatus::Void)
                        <flux:menu.item icon="pencil" :href="route('vendor-credits.edit', ['company' => $company->slug, 'vendor_credit' => $vendorCredit->id])" wire:navigate data-test="edit-vendor-credit-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($vendorCredit->status === VendorCreditStatus::Posted)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this vendor credit? A reversing GL entry will be posted.') }}" data-test="void-vendor-credit-button">
                            {{ __('Void') }}
                        </flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Unit') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($vendorCredit->lines as $line)
                    <tr>
                        <td class="px-4 py-2">{{ $line->description }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        <td class="px-4 py-2 text-right">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->unit_price_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ optional($line->taxCode)->code }}
                            @if ($line->secondaryTaxCode)
                                <span class="block">{{ $line->secondaryTaxCode->code }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_total_cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($vendorCredit->subtotal_cents / 100, 2) }}</td>
                </tr>
                @php
                    $taxRows = \App\Support\Tax\LineTaxBreakdown::forLines($vendorCredit->lines);
                @endphp
                @forelse ($taxRows as $taxRow)
                    <tr data-test="vendor-credit-tax-row">
                        <td colspan="5" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right font-medium">{{ __('Tax') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($vendorCredit->tax_cents / 100, 2) }}</td>
                    </tr>
                @endforelse
                <tr class="text-base">
                    <td colspan="5" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($vendorCredit->total_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($vendorCredit->memo) <flux:text class="mt-4 text-muted-foreground">{{ $vendorCredit->memo }}</flux:text> @endif

    <div class="mt-6 space-y-3 rounded-lg border border-border p-4" data-test="vendor-credit-attachments">
        <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

        @forelse ($this->attachments as $att)
            <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="att-{{ $att->id }}" data-test="vendor-credit-attachment-row">
                <x-attachment-link :attachment="$att" :company="$company" />
                <flux:button variant="ghost" size="sm" icon="x-mark"
                    wire:click="removeAttachment({{ $att->id }})"
                    wire:confirm="{{ __('Remove this attachment?') }}"
                    data-test="vendor-credit-attachment-remove" />
            </div>
        @empty
            <flux:text class="text-sm text-muted-foreground">{{ __('No attachments yet.') }}</flux:text>
        @endforelse

        <x-attachment-dropzone model="newAttachments"
            accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
            :description="__('PDF, images, or Office docs up to 10 MB each.')"
            data-test="vendor-credit-attachment-input" />

        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        @if (count($newAttachments) > 0)
            <flux:button variant="filled" wire:click="uploadAttachments" data-test="vendor-credit-attachment-upload">
                {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
            </flux:button>
        @endif
    </div>
</section>
