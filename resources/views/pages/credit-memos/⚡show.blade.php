<?php

use App\Actions\Sales\RecordCustomerRefund;
use App\Enums\ChequeStatus;
use App\Enums\CreditMemoStatus;
use App\Enums\ReceiptStatus;
use App\Livewire\Attributes\GuardsEditLock;
use App\Livewire\Concerns\IssuesCustomerRefunds;
use App\Livewire\Concerns\ShowsEditLock;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\InvoiceSetting;
use App\Services\AttachmentService;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\ReceiptPoster;
use App\Support\Tax\LineTaxBreakdown;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Credit memo')] class extends Component {
    use IssuesCustomerRefunds;
    use ShowsEditLock;
    use WithFileUploads;

    public Company $company;

    public CreditMemo $creditMemo;

    public array $newAttachments = [];

    /**
     * Which optional line columns are shown for this document, read from and
     * persisted to the shared per-company InvoiceSetting.
     *
     * @var array<string, bool>
     */
    public array $fieldVisibility = [];

    /**
     * The toggleable line columns, keyed by the property name used in
     * $fieldVisibility, mapped to the InvoiceSetting column that persists them.
     *
     * @var array<string, string>
     */
    protected const VISIBILITY_MAP = [
        'item_column' => 'show_item_column',
        'qty_column' => 'show_qty_column',
        'tax_column' => 'show_tax_column',
        'service_date_column' => 'show_service_date_column',
    ];

    protected function editLockRecord(): ?Model
    {
        return $this->creditMemo;
    }

    public function mount(Company $company, CreditMemo $credit_memo): void
    {
        $this->company = $company;
        $this->creditMemo = $credit_memo->load('lines.item', 'lines.account', 'lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'contact', 'salesRep', 'journalEntry');

        $this->loadFieldVisibility();
    }

    protected function loadFieldVisibility(): void
    {
        $settings = $this->company->invoiceSettingsOrNew();

        foreach (self::VISIBILITY_MAP as $key => $column) {
            $this->fieldVisibility[$key] = (bool) $settings->{$column};
        }
    }

    /**
     * Persist a toggle change immediately so the layout sticks for the company.
     * Only the mapped line columns are written, leaving header-field flags intact.
     */
    public function updatedFieldVisibility(): void
    {
        $payload = [];

        foreach (self::VISIBILITY_MAP as $key => $column) {
            $payload[$column] = (bool) ($this->fieldVisibility[$key] ?? true);
        }

        InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], $payload);
    }

    /**
     * Columns spanned by the totals labels in the footer: every line column
     * except the final Total amount. Always-on columns are Description, Account,
     * Unit, Subtotal and Tax amount (5); Item, Qty and the Tax code are optional.
     */
    #[Computed]
    public function lineLeadingColspan(): int
    {
        return 5
            + (int) ($this->fieldVisibility['item_column'] ?? true)
            + (int) ($this->fieldVisibility['qty_column'] ?? true)
            + (int) ($this->fieldVisibility['tax_column'] ?? true);
    }

    #[GuardsEditLock]
    public function void(CreditMemoPoster $poster): void
    {
        try {
            $poster->void($this->creditMemo);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Credit memo voided.'));
        $this->redirectRoute('credit-memos.index', ['company' => $this->company->slug], navigate: true);
    }

    #[GuardsEditLock]
    public function submitRefund(RecordCustomerRefund $action): void
    {
        $this->processRefund($action);
    }

    #[GuardsEditLock]
    public function deleteRefundCheque(int $chequeId, ChequePoster $poster): void
    {
        $this->removeRefundCheque($chequeId, $poster);
    }

    #[GuardsEditLock]
    public function deleteRefundReceipt(int $receiptId, ReceiptPoster $poster): void
    {
        $this->removeRefundReceipt($receiptId, $poster);
    }

    /**
     * A posted credit memo with credit left refunds itself.
     */
    protected function eligibleRefundSources(): Collection
    {
        return $this->creditMemo->status === CreditMemoStatus::Posted && $this->creditMemo->remainingRefundableCents() > 0
            ? collect([$this->creditMemo])
            : collect();
    }

    protected function removableRefundCheques(): HasMany
    {
        return $this->creditMemo->refundCheques();
    }

    protected function removableRefundReceipts(): HasMany
    {
        return $this->creditMemo->refundReceipts();
    }

    protected function afterRefundChanged(): void
    {
        unset($this->refundCheques, $this->refundReceipts);
        $this->creditMemo->refresh();
    }

    #[Computed]
    public function refundCheques()
    {
        return $this->creditMemo->refundCheques()->where('status', '!=', ChequeStatus::Void->value)->orderBy('id')->get();
    }

    #[Computed]
    public function refundReceipts()
    {
        return $this->creditMemo->refundReceipts()->where('status', '!=', ReceiptStatus::Void->value)->orderBy('id')->get();
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->creditMemo, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        $service->remove(Attachment::findOrFail($id), $this->creditMemo);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    #[Computed]
    public function attachments()
    {
        return $this->creditMemo->attachments()->get();
    }
}; ?>

<section class="w-full">
    <x-edit-lock.banner :lock="$this->editLockBanner" />

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Credit memo') }} {{ $creditMemo->credit_memo_no }}</flux:heading>
            <flux:subheading>
                {{ $creditMemo->contact->display_name }} &middot;
                {{ $creditMemo->credit_memo_date->toDateString() }}
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($creditMemo->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($creditMemo->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $creditMemo->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($creditMemo->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif

                @if ($creditMemo->isFullyRefunded())
                    <flux:badge color="green" data-test="fully-refunded-badge">{{ __('Refunded') }}</flux:badge>
                @elseif ($creditMemo->refundedCents() > 0)
                    <flux:badge color="green" data-test="partially-refunded-badge">{{ __('Partly refunded') }}</flux:badge>
                @endif
            </div>

            @if ($creditMemo->salesRep)
                <div class="mt-2 text-sm text-muted-foreground" data-test="credit-memo-meta">{{ __('Sales rep') }}: {{ $creditMemo->salesRep->display_name }}</div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->canRefund)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" icon="banknotes" wire:click="openRefund" data-test="refund-credit-memo-button">
                    {{ __('Refund to client') }}
                </flux:button>
            @endif

            <flux:dropdown align="end" data-test="credit-memo-show-field-settings">
                <flux:button variant="ghost" icon="adjustments-horizontal" icon:trailing="chevron-down">{{ __('Columns') }}</flux:button>

                <flux:menu>
                    <flux:menu.group :heading="__('Line columns')">
                        <flux:menu.checkbox wire:model.live="fieldVisibility.item_column" keep-open>{{ __('Item') }}</flux:menu.checkbox>
                        <flux:menu.checkbox wire:model.live="fieldVisibility.qty_column" keep-open>{{ __('Qty') }}</flux:menu.checkbox>
                        <flux:menu.checkbox wire:model.live="fieldVisibility.tax_column" keep-open>{{ __('Tax') }}</flux:menu.checkbox>
                        <flux:menu.checkbox wire:model.live="fieldVisibility.service_date_column" keep-open>{{ __('Service date') }}</flux:menu.checkbox>
                    </flux:menu.group>
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="credit-memo-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($this->canRefund)
                        <flux:menu.item class="lg:hidden" icon="banknotes" wire:click="openRefund" data-test="refund-credit-memo-menu-item">
                            {{ __('Refund to client') }}
                        </flux:menu.item>
                    @endif
                    @if ($creditMemo->status !== CreditMemoStatus::Draft)
                        <flux:menu.item icon="printer" :href="route('credit-memos.print', ['company' => $company->slug, 'credit_memo' => $creditMemo->id])" target="_blank" data-test="print-credit-memo-button">
                            {{ __('Print') }}
                        </flux:menu.item>
                    @endif
                    @if ($creditMemo->status !== CreditMemoStatus::Void)
                        <flux:menu.item icon="pencil" :href="route('credit-memos.edit', ['company' => $company->slug, 'credit_memo' => $creditMemo->id])" wire:navigate data-test="edit-credit-memo-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($creditMemo->status !== CreditMemoStatus::Void && $creditMemo->status !== CreditMemoStatus::Draft)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this credit memo? A reversing GL entry will be posted.') }}" data-test="void-credit-memo-button">
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
                    @if ($fieldVisibility['item_column'])
                        <th class="px-4 py-2 text-left">{{ __('Item') }}</th>
                    @endif
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    @if ($fieldVisibility['qty_column'])
                        <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                    @endif
                    <th class="px-4 py-2 text-right">{{ __('Unit') }}</th>
                    @if ($fieldVisibility['tax_column'])
                        <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                    @endif
                    <th class="px-4 py-2 text-right">{{ __('Subtotal') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($creditMemo->lines as $line)
                    <tr>
                        @if ($fieldVisibility['item_column'])
                            <td class="px-4 py-2 text-muted-foreground">{{ optional($line->item)->name }}</td>
                        @endif
                        <td class="px-4 py-2">
                            {{ $line->description }}
                            @if ($fieldVisibility['service_date_column'] && $line->service_date)
                                <div class="text-xs text-muted-foreground">{{ __('Service date') }}: {{ $line->service_date->toDateString() }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        @if ($fieldVisibility['qty_column'])
                            <td class="px-4 py-2 text-right">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                        @endif
                        <td class="px-4 py-2 text-right font-mono">
                            {{ number_format($line->unit_price_cents / 100, 2) }}
                            @if ($line->line_discount_cents)
                                <div class="text-xs text-muted-foreground">{{ __('less :amt disc', ['amt' => number_format($line->line_discount_cents / 100, 2)]) }}</div>
                            @endif
                        </td>
                        @if ($fieldVisibility['tax_column'])
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ optional($line->taxCode)->label() }}
                                @if ($line->secondaryTaxCode)
                                    <span class="block">{{ $line->secondaryTaxCode->label() }}</span>
                                @endif
                            </td>
                        @endif
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_subtotal_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ number_format($line->line_tax_cents / 100, 2) }}
                            @if ($line->secondary_tax_cents)
                                <span class="block">{{ number_format($line->secondary_tax_cents / 100, 2) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_total_cents / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr>
                    <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($creditMemo->subtotal_cents / 100, 2) }}</td>
                </tr>
                @php
                    $taxRows = LineTaxBreakdown::forLines($creditMemo->lines);
                @endphp
                @forelse ($taxRows as $taxRow)
                    <tr data-test="credit-memo-tax-row">
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-medium">{{ LineTaxBreakdown::labelWithRate($taxRow['label'], $taxRow['rate']) }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-medium">{{ __('Tax') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($creditMemo->tax_cents / 100, 2) }}</td>
                    </tr>
                @endforelse
                <tr class="text-base">
                    <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($creditMemo->total_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @php
        $taxRegistrations = $creditMemo->lines
            ->flatMap(fn ($line) => [optional($line->taxCode)->agency, optional($line->secondaryTaxCode)->agency])
            ->filter(fn ($agency) => $agency && filled($agency->registration_number))
            ->unique('id')
            ->values();
    @endphp

    @if ($taxRegistrations->isNotEmpty())
        <div class="mt-4 space-y-1 text-sm text-muted-foreground" data-test="credit-memo-tax-registrations">
            @foreach ($taxRegistrations as $agency)
                <div>{{ $agency->label() }}: <span class="font-mono">{{ $agency->registration_number }}</span></div>
            @endforeach
        </div>
    @endif

    @if ($creditMemo->memo)
        <flux:text class="mt-4 text-muted-foreground">{{ $creditMemo->memo }}</flux:text>
    @endif

    @if ($creditMemo->customer_message)
        <flux:text class="mt-2 text-muted-foreground" data-test="credit-memo-customer-message">{{ $creditMemo->customer_message }}</flux:text>
    @endif

    @if ($this->refundCheques->isNotEmpty() || $this->refundReceipts->isNotEmpty())
        <div class="mt-6 space-y-2 rounded-lg border border-border p-4" data-test="credit-memo-refunds">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Refunds') }}</flux:heading>
                <flux:text class="text-sm text-muted-foreground">
                    {{ __('Refunded :refunded of :total', [
                        'refunded' => number_format($creditMemo->refundedCents() / 100, 2),
                        'total' => number_format($creditMemo->total_cents / 100, 2),
                    ]) }}
                </flux:text>
            </div>

            <x-customer-refund.list :company="$company" :cheques="$this->refundCheques" :receipts="$this->refundReceipts" />
        </div>
    @endif

    <x-customer-refund.modal
        :contact-name="$creditMemo->contact->display_name"
        :method="$refundMethod"
        :bank-accounts="$this->refundBankAccounts"
        :deposit-accounts="$this->refundDepositAccounts"
        :payment-methods="$this->refundPaymentMethods"
    />

    <div class="mt-6 space-y-3 rounded-lg border border-border p-4" data-test="credit-memo-attachments">
        <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

        @forelse ($this->attachments as $att)
            <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="att-{{ $att->id }}" data-test="credit-memo-attachment-row">
                <x-attachment-link :attachment="$att" :company="$company" />
                <flux:button variant="ghost" size="sm" icon="x-mark"
                    wire:click="removeAttachment({{ $att->id }})"
                    wire:confirm="{{ __('Remove this attachment?') }}"
                    data-test="credit-memo-attachment-remove" />
            </div>
        @empty
            <flux:text class="text-sm text-muted-foreground">{{ __('No attachments yet.') }}</flux:text>
        @endforelse

        <x-attachment-dropzone model="newAttachments"
            accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
            :description="__('PDF, images, or Office docs up to 10 MB each.')"
            data-test="credit-memo-attachment-input" />

        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        @if (count($newAttachments) > 0)
            <flux:button variant="filled" wire:click="uploadAttachments" data-test="credit-memo-attachment-upload">
                {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
            </flux:button>
        @endif
    </div>
</section>
