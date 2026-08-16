<?php

use App\Enums\AccountSubtype;
use App\Enums\BillPaymentStatus;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Company;
use App\Services\AttachmentService;
use App\Services\Posting\BillPoster;
use App\Services\Posting\BillReconciler;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Bill')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public Bill $bill;

    public array $newAttachments = [];

    public function mount(Company $company, Bill $bill): void
    {
        $this->company = $company;
        $this->bill = $bill->load('lines.account', 'lines.taxCode', 'lines.secondaryTaxCode', 'contact', 'journalEntry', 'paymentApplications.payment');
    }

    public function void(BillPoster $poster): void
    {
        try {
            $poster->void($this->bill);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Bill voided.'));
        $this->redirectRoute('bills.index', ['company' => $this->company->slug], navigate: true);
    }

    /**
     * Cents of this bill's balance the ledger has already settled (via a journal entry
     * to AP) and could therefore be closed without posting anything.
     */
    #[Computed]
    public function reconcilableCents(): int
    {
        if ($this->bill->contact_id === null || ! $this->bill->status->isOpen()) {
            return 0;
        }

        $available = app(BillReconciler::class)
            ->availableToReconcile($this->company, (int) $this->bill->contact_id, $this->company->currentDateTime());

        return min($this->bill->balanceCents(), $available);
    }

    public function reconcile(BillReconciler $reconciler): void
    {
        $closed = $reconciler->reconcileBill($this->bill);

        if ($closed <= 0) {
            Flux::toast(variant: 'warning', text: __('Nothing to close — this balance is still owed in the ledger.'));

            return;
        }

        $this->bill->refresh();
        unset($this->reconcilableCents);

        Flux::toast(variant: 'success', text: __('Closed :amount already settled in the ledger.', ['amount' => '$'.number_format($closed / 100, 2)]));
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->bill, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        $service->remove(Attachment::findOrFail($id), $this->bill);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    #[Computed]
    public function attachments()
    {
        return $this->bill->attachments()->get();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Bill') }} {{ $bill->bill_no }}</flux:heading>
            <flux:subheading>
                {{ $bill->contact->display_name }} &middot;
                {{ $bill->bill_date->toDateString() }} &middot;
                {{ __('Due') }} {{ $bill->due_date->toDateString() }}
                @if ($bill->vendor_reference) &middot; {{ __('Ref') }}: {{ $bill->vendor_reference }} @endif
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($bill->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                    @case('partial') <flux:badge color="indigo">{{ __('Partial') }}</flux:badge> @break
                    @case('paid') <flux:badge color="green">{{ __('Paid') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($bill->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $bill->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($bill->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($bill->status !== BillStatus::Void && $bill->status !== BillStatus::Draft && $bill->balanceCents() > 0)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" :href="route('bill-payments.create', ['company' => $company->slug, 'bill' => $bill->id])" wire:navigate data-test="pay-bill-button">
                    {{ __('Pay bill') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="bill-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($bill->status !== BillStatus::Void && $bill->status !== BillStatus::Draft && $bill->balanceCents() > 0)
                        <flux:menu.item class="lg:hidden" icon="credit-card" :href="route('bill-payments.create', ['company' => $company->slug, 'bill' => $bill->id])" wire:navigate data-test="pay-bill-menu-item">
                            {{ __('Pay bill') }}
                        </flux:menu.item>
                    @endif
                    <flux:menu.item icon="printer" :href="route('bills.print', ['company' => $company->slug, 'bill' => $bill->id])" target="_blank" data-test="print-bill-button">
                        {{ __('Print') }}
                    </flux:menu.item>
                    @if ($bill->status !== BillStatus::Void)
                        <flux:menu.item icon="pencil" :href="route('bills.edit', ['company' => $company->slug, 'bill' => $bill->id])" wire:navigate data-test="edit-bill-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    <flux:menu.item icon="document-duplicate" :href="route('bills.create', ['company' => $company->slug, 'from' => $bill->id])" wire:navigate data-test="duplicate-bill-button">
                        {{ __('Duplicate') }}
                    </flux:menu.item>
                    @if ($bill->status !== BillStatus::Void && $bill->status !== BillStatus::Draft && $bill->bill_type === BillType::Vendor && $bill->balanceCents() > 0 && $this->reconcilableCents > 0)
                        <flux:menu.item
                            icon="check-circle"
                            wire:click="reconcile"
                            wire:confirm="{{ __('Close the remaining :amount? It was already settled by a journal entry, so no new ledger entry is posted.', ['amount' => '$'.number_format($this->reconcilableCents / 100, 2)]) }}"
                            data-test="reconcile-bill-button"
                        >{{ __('Close settled balance') }}</flux:menu.item>
                    @endif
                    @if ($bill->status !== BillStatus::Void && $bill->status !== BillStatus::Draft)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this bill? A reversing GL entry will be posted.') }}" data-test="void-bill-button">
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
                    <th class="px-4 py-2 text-right">{{ __('Subtotal') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($bill->lines as $line)
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
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_subtotal_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ number_format($line->line_tax_cents / 100, 2) }}
                            @if ($line->secondary_tax_cents)
                                <span class="block">{{ number_format($line->secondary_tax_cents / 100, 2) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->line_total_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right">
                            @if (optional($line->account)->subtype === AccountSubtype::FixedAsset)
                                <flux:tooltip :content="__('Create asset record')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="cube"
                                        :href="route('assets.create', ['company' => $company->slug, 'source_type' => 'bill_line', 'source_id' => $line->id])"
                                        wire:navigate
                                        data-test="create-asset-from-bill-line"
                                    />
                                </flux:tooltip>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr>
                    <td colspan="7" class="px-4 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($bill->subtotal_cents / 100, 2) }}</td>
                </tr>
                @php
                    $taxRows = \App\Support\Tax\LineTaxBreakdown::forLines($bill->lines);
                @endphp
                @forelse ($taxRows as $taxRow)
                    <tr data-test="bill-tax-row">
                        <td colspan="7" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-2 text-right font-medium">{{ __('Tax') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($bill->tax_cents / 100, 2) }}</td>
                    </tr>
                @endforelse
                <tr class="text-base">
                    <td colspan="7" class="px-4 py-2 text-right font-semibold">{{ __('Total') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($bill->total_cents / 100, 2) }}</td>
                </tr>
                <tr>
                    <td colspan="7" class="px-4 py-2 text-right font-medium">{{ __('Paid') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($bill->amount_paid_cents / 100, 2) }}</td>
                </tr>
                <tr class="text-base">
                    <td colspan="7" class="px-4 py-2 text-right font-semibold">{{ __('Balance due') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($bill->balanceCents() / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @php($appliedPayments = $bill->paymentApplications->filter(fn ($a) => $a->payment && $a->payment->status !== BillPaymentStatus::Void))
    @if ($appliedPayments->isNotEmpty())
        <div class="mt-6" data-test="bill-payment-applications">
            <flux:heading size="sm" class="mb-2">{{ __('Payments applied') }}</flux:heading>
            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ __('Payment #') }}</th>
                            <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                            <th class="px-4 py-2 text-right">{{ __('Applied') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($appliedPayments as $app)
                            <tr wire:key="bpa-{{ $app->id }}" data-test="bill-payment-application-row">
                                <td class="px-4 py-2 font-mono">
                                    <a href="{{ route('bill-payments.show', ['company' => $company->slug, 'payment' => $app->payment->id]) }}" wire:navigate class="underline">{{ $app->payment->payment_no }}</a>
                                </td>
                                <td class="px-4 py-2">{{ $app->payment->payment_date->toDateString() }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($app->amount_cents / 100, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($bill->memo) <flux:text class="mt-4 text-muted-foreground">{{ $bill->memo }}</flux:text> @endif

    <div class="mt-6 space-y-3 rounded-lg border border-border p-4" data-test="bill-attachments">
        <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

        @forelse ($this->attachments as $att)
            <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="att-{{ $att->id }}" data-test="bill-attachment-row">
                <x-attachment-link :attachment="$att" :company="$company" />
                <flux:button variant="ghost" size="sm" icon="x-mark"
                    wire:click="removeAttachment({{ $att->id }})"
                    wire:confirm="{{ __('Remove this attachment?') }}"
                    data-test="bill-attachment-remove" />
            </div>
        @empty
            <flux:text class="text-sm text-muted-foreground">{{ __('No attachments yet.') }}</flux:text>
        @endforelse

        <x-attachment-dropzone model="newAttachments"
            accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
            :description="__('PDF, images, or Office docs up to 10 MB each.')"
            data-test="bill-attachment-input" />

        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        @if (count($newAttachments) > 0)
            <flux:button variant="filled" wire:click="uploadAttachments" data-test="bill-attachment-upload">
                {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
            </flux:button>
        @endif
    </div>
</section>
