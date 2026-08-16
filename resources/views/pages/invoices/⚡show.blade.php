<?php

use App\Actions\Sales\SaveInvoicePaymentRequests;
use App\Actions\Sales\SendInvoiceToCustomer;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentRequestType;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Services\Sales\PaymentRequestScheduleStatus;
use App\Support\Money;
use App\Services\AttachmentService;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\InvoiceReconciler;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Invoice')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public Invoice $invoice;

    public array $newAttachments = [];

    public string $sendToEmail = '';

    public string $sendCc = '';

    public string $sendBcc = '';

    public bool $sendCcSelf = false;

    public string $sendMessage = '';

    /**
     * Editable milestone rows: [{label, type: percent|fixed, value, due_date}].
     *
     * @var array<int, array{label: string, type: string, value: string, due_date: ?string}>
     */
    public array $milestones = [];

    public bool $editingSchedule = false;

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
        'account_column' => 'show_account_column',
        'unit_column' => 'show_unit_column',
    ];

    public function mount(Company $company, Invoice $invoice): void
    {
        $this->company = $company;
        $this->invoice = $invoice->load('lines.item', 'lines.account', 'lines.taxCode.agency', 'lines.secondaryTaxCode.agency', 'contact', 'salesRep', 'journalEntry');

        $this->loadFieldVisibility();

        $settings = $company->invoiceSettingsOrNew();
        $this->sendToEmail = (string) ($this->invoice->contact->email ?? '');
        $this->sendCcSelf = (bool) $settings->email_cc_self;
        $this->sendMessage = (string) ($settings->email_default_message
            ?? __('Please find your invoice attached. You can view and pay it online using the button below.'));

        $this->loadMilestones();
    }

    protected function loadMilestones(): void
    {
        $this->milestones = $this->invoice->paymentRequests
            ->map(fn ($request): array => [
                'label' => (string) $request->label,
                'type' => $request->request_type->value,
                'value' => $request->request_type === PaymentRequestType::Percent
                    ? rtrim(rtrim((string) $request->percent, '0'), '.')
                    : Money::fromCents((int) $request->amount_cents)->toDecimalString(),
                'due_date' => $request->due_date?->toDateString(),
            ])
            ->all();
    }

    /**
     * The derived schedule (statuses computed from payments) for display.
     *
     * @return \Illuminate\Support\Collection<int, array{request: \App\Models\InvoicePaymentRequest, status: \App\Enums\PaymentRequestStatus}>
     */
    #[Computed]
    public function paymentSchedule(): \Illuminate\Support\Collection
    {
        return app(PaymentRequestScheduleStatus::class)->for($this->invoice);
    }

    public function addMilestone(): void
    {
        $this->milestones[] = ['label' => '', 'type' => 'percent', 'value' => '', 'due_date' => null];
        $this->editingSchedule = true;
    }

    public function removeMilestone(int $index): void
    {
        unset($this->milestones[$index]);
        $this->milestones = array_values($this->milestones);
    }

    public function savePaymentSchedule(SaveInvoicePaymentRequests $action): void
    {
        $requests = collect($this->milestones)
            ->filter(fn (array $m): bool => trim($m['label']) !== '' && trim((string) $m['value']) !== '')
            ->map(fn (array $m): array => $m['type'] === PaymentRequestType::Percent->value
                ? ['label' => $m['label'], 'type' => 'percent', 'percent' => (float) $m['value'], 'due_date' => $m['due_date'] ?: null]
                : ['label' => $m['label'], 'type' => 'fixed', 'amount_cents' => Money::fromString((string) $m['value'])->cents, 'due_date' => $m['due_date'] ?: null])
            ->values()
            ->all();

        try {
            $action->handle($this->invoice, $requests);
        } catch (\App\Exceptions\Posting\PostingValidationException $e) {
            $this->addError('milestones', $e->getMessage());

            return;
        }

        $this->invoice->load('paymentRequests');
        $this->loadMilestones();
        $this->editingSchedule = false;
        unset($this->paymentSchedule);
        Flux::toast(variant: 'success', text: __('Payment schedule saved.'));
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
     * except the final Total amount. Always-on columns are Description, Subtotal
     * and Tax amount (3); Item, Qty, Account, Unit and the Tax code are optional.
     */
    #[Computed]
    public function lineLeadingColspan(): int
    {
        return 3
            + (int) ($this->fieldVisibility['item_column'] ?? true)
            + (int) ($this->fieldVisibility['qty_column'] ?? true)
            + (int) ($this->fieldVisibility['tax_column'] ?? true)
            + (int) ($this->fieldVisibility['account_column'] ?? true)
            + (int) ($this->fieldVisibility['unit_column'] ?? true);
    }

    public function sendToClient(SendInvoiceToCustomer $action): void
    {
        $this->validate([
            'sendToEmail' => ['required', 'string'],
            'sendCc' => ['nullable', 'string'],
            'sendBcc' => ['nullable', 'string'],
            'sendMessage' => ['nullable', 'string', 'max:2000'],
        ]);

        $to = $this->parseEmails($this->sendToEmail, 'sendToEmail');
        $cc = $this->parseEmails($this->sendCc, 'sendCc');
        $bcc = $this->parseEmails($this->sendBcc, 'sendBcc');

        if ($to === []) {
            throw ValidationException::withMessages(['sendToEmail' => __('Enter at least one recipient.')]);
        }

        // "CC my business email" copies the signed-in user, unless they're already
        // a recipient, so the sender keeps a record of exactly what went out.
        if ($this->sendCcSelf && ($me = Auth::user()?->email) && ! in_array($me, [...$to, ...$cc], true)) {
            $cc[] = $me;
        }

        // A human filled in this modal and clicked Send, so it goes out regardless of
        // the customer's automated-email preference, which is left untouched.
        $action->handle($this->company, $this->invoice, $to, (string) $this->sendMessage, $cc, $bcc, bypassOptIn: true);

        Flux::modal('send-invoice')->close();
        Flux::toast(variant: 'success', text: __('Invoice sent to :email.', ['email' => implode(', ', $to)]));
    }

    /**
     * Split a comma-separated address field into a validated, de-duplicated list,
     * failing the given field if any address is malformed.
     *
     * @return list<string>
     */
    private function parseEmails(?string $raw, string $field): array
    {
        $emails = collect(explode(',', (string) $raw))
            ->map(fn (string $email): string => trim($email))
            ->filter()
            ->unique()
            ->values();

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([$field => __(':email is not a valid email address.', ['email' => $email])]);
            }
        }

        return $emails->all();
    }

    public function void(InvoicePoster $poster): void
    {
        try {
            $poster->void($this->invoice);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Invoice voided.'));
        $this->redirectRoute('invoices.index', ['company' => $this->company->slug], navigate: true);
    }

    /**
     * Cents of this invoice's balance the ledger has already settled (via a journal
     * entry to AR) and could therefore be closed without posting anything.
     */
    #[Computed]
    public function reconcilableCents(): int
    {
        if ($this->invoice->contact_id === null || ! $this->invoice->status->isOpen()) {
            return 0;
        }

        $available = app(InvoiceReconciler::class)
            ->availableToReconcile($this->company, (int) $this->invoice->contact_id, $this->company->currentDateTime());

        return min($this->invoice->balanceCents(), $available);
    }

    public function reconcile(InvoiceReconciler $reconciler): void
    {
        $closed = $reconciler->reconcileInvoice($this->invoice);

        if ($closed <= 0) {
            Flux::toast(variant: 'warning', text: __('Nothing to close — this balance is still owed in the ledger.'));

            return;
        }

        $this->invoice->refresh();
        unset($this->reconcilableCents);

        Flux::toast(variant: 'success', text: __('Closed :amount already settled in the ledger.', ['amount' => '$'.number_format($closed / 100, 2)]));
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->invoice, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        $service->remove(Attachment::findOrFail($id), $this->invoice);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    #[Computed]
    public function attachments()
    {
        return $this->invoice->attachments()->get();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Invoice') }} {{ $invoice->invoice_no }}</flux:heading>
            <flux:subheading>
                {{ $invoice->contact->display_name }} &middot;
                {{ $invoice->invoice_date->toDateString() }} &middot;
                {{ __('Due') }} {{ $invoice->due_date->toDateString() }}
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($invoice->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="blue">{{ __('Posted') }}</flux:badge> @break
                    @case('partial') <flux:badge color="indigo">{{ __('Partial') }}</flux:badge> @break
                    @case('paid') <flux:badge color="green">{{ __('Paid') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($invoice->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $invoice->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($invoice->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>

            @if ($invoice->salesRep || $invoice->customer_po || $invoice->ship_date || $invoice->ship_via || $invoice->fob || $invoice->tracking_no)
                <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted-foreground" data-test="invoice-meta">
                    @if ($invoice->salesRep)<div>{{ __('Sales rep') }}: {{ $invoice->salesRep->display_name }}</div>@endif
                    @if ($invoice->customer_po)<div>{{ __('PO #') }}: {{ $invoice->customer_po }}</div>@endif
                    @if ($invoice->ship_date)<div>{{ __('Ship date') }}: {{ $invoice->ship_date->toDateString() }}</div>@endif
                    @if ($invoice->ship_via)<div>{{ __('Ship via') }}: {{ $invoice->ship_via }}</div>@endif
                    @if ($invoice->fob)<div>{{ __('FOB') }}: {{ $invoice->fob }}</div>@endif
                    @if ($invoice->tracking_no)<div>{{ __('Tracking #') }}: {{ $invoice->tracking_no }}</div>@endif
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($invoice->status !== InvoiceStatus::Void && $invoice->status !== InvoiceStatus::Draft && $invoice->balanceCents() > 0)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" :href="route('receipts.create', ['company' => $company->slug, 'invoice' => $invoice->id])" wire:navigate data-test="receive-payment-button">
                    {{ __('Receive payment') }}
                </flux:button>
            @endif

            <flux:dropdown align="end" data-test="invoice-show-field-settings">
                <flux:button variant="ghost" icon="adjustments-horizontal" icon:trailing="chevron-down">{{ __('Columns') }}</flux:button>

                <flux:menu>
                    <flux:menu.group :heading="__('Line columns')">
                        <flux:menu.checkbox wire:model.live="fieldVisibility.item_column" keep-open>{{ __('Item') }}</flux:menu.checkbox>
                        <flux:menu.checkbox wire:model.live="fieldVisibility.account_column" keep-open>{{ __('Account') }}</flux:menu.checkbox>
                        <flux:menu.checkbox wire:model.live="fieldVisibility.qty_column" keep-open>{{ __('Qty') }}</flux:menu.checkbox>
                        <flux:menu.checkbox wire:model.live="fieldVisibility.unit_column" keep-open>{{ __('Unit') }}</flux:menu.checkbox>
                        <flux:menu.checkbox wire:model.live="fieldVisibility.tax_column" keep-open>{{ __('Tax') }}</flux:menu.checkbox>
                        <flux:menu.checkbox wire:model.live="fieldVisibility.service_date_column" keep-open>{{ __('Service date') }}</flux:menu.checkbox>
                    </flux:menu.group>
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="invoice-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($invoice->status !== InvoiceStatus::Void && $invoice->status !== InvoiceStatus::Draft && $invoice->balanceCents() > 0)
                        <flux:menu.item class="lg:hidden" icon="banknotes" :href="route('receipts.create', ['company' => $company->slug, 'invoice' => $invoice->id])" wire:navigate data-test="receive-payment-menu-item">
                            {{ __('Receive payment') }}
                        </flux:menu.item>
                    @endif
                    @if ($invoice->status !== InvoiceStatus::Draft)
                        <flux:menu.item icon="printer" :href="route('invoices.print', ['company' => $company->slug, 'invoice' => $invoice->id])" target="_blank" data-test="print-invoice-button">
                            {{ __('Print') }}
                        </flux:menu.item>
                    @endif
                    @if ($invoice->status !== InvoiceStatus::Draft && $invoice->status !== InvoiceStatus::Void)
                        <flux:modal.trigger name="send-invoice">
                            <flux:menu.item icon="paper-airplane" data-test="send-invoice-button">
                                {{ $invoice->member_id ? __('Send to member') : __('Send to client') }}
                            </flux:menu.item>
                        </flux:modal.trigger>
                    @endif
                    @if ($invoice->status !== InvoiceStatus::Void)
                        <flux:menu.item icon="pencil" :href="route('invoices.edit', ['company' => $company->slug, 'invoice' => $invoice->id])" wire:navigate data-test="edit-invoice-button">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($invoice->status !== InvoiceStatus::Void && $invoice->status !== InvoiceStatus::Draft)
                        <flux:menu.item icon="receipt-refund" :href="route('credit-memos.create', ['company' => $company->slug, 'invoice' => $invoice->id])" wire:navigate data-test="credit-memo-invoice-button">
                            {{ __('Credit Memo') }}
                        </flux:menu.item>
                    @endif
                    @if ($invoice->status !== InvoiceStatus::Void && $invoice->status !== InvoiceStatus::Draft && $invoice->balanceCents() > 0 && $this->reconcilableCents > 0)
                        <flux:menu.item
                            icon="check-circle"
                            wire:click="reconcile"
                            wire:confirm="{{ __('Close the remaining :amount? It was already settled by a journal entry, so no new ledger entry is posted.', ['amount' => '$'.number_format($this->reconcilableCents / 100, 2)]) }}"
                            data-test="reconcile-invoice-button"
                        >{{ __('Close settled balance') }}</flux:menu.item>
                    @endif
                    @if ($invoice->status !== InvoiceStatus::Void && $invoice->status !== InvoiceStatus::Draft)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this invoice? A reversing GL entry will be posted.') }}" data-test="void-invoice-button">
                            {{ __('Void') }}
                        </flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full min-w-[42rem] text-sm">
            <thead class="bg-muted">
                <tr>
                    @if ($fieldVisibility['item_column'])
                        <th class="px-4 py-2 text-left">{{ __('Item') }}</th>
                    @endif
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    @if ($fieldVisibility['account_column'])
                        <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    @endif
                    @if ($fieldVisibility['qty_column'])
                        <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                    @endif
                    @if ($fieldVisibility['unit_column'])
                        <th class="px-4 py-2 text-right">{{ __('Unit') }}</th>
                    @endif
                    @if ($fieldVisibility['tax_column'])
                        <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                    @endif
                    <th class="px-4 py-2 text-right">{{ __('Subtotal') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($invoice->lines as $line)
                    <tr>
                        @if ($fieldVisibility['item_column'])
                            <td class="px-4 py-2 text-muted-foreground">{{ optional($line->item)->name }}</td>
                        @endif
                        <td class="px-4 py-2">
                            {!! \App\Support\Text\LineDescription::toHtml($line->description) !!}
                            @if ($fieldVisibility['service_date_column'] && $line->service_date)
                                <div class="text-xs text-muted-foreground">{{ __('Service date') }}: {{ $line->service_date->toDateString() }}</div>
                            @endif
                        </td>
                        @if ($fieldVisibility['account_column'])
                            <td class="px-4 py-2 text-muted-foreground">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        @endif
                        @if ($fieldVisibility['qty_column'])
                            <td class="px-4 py-2 text-right">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }}</td>
                        @endif
                        @if ($fieldVisibility['unit_column'])
                            <td class="px-4 py-2 text-right font-mono">
                                {{ number_format($line->unit_price_cents / 100, 2) }}
                                @if ($line->line_discount_cents)
                                    <div class="text-xs text-muted-foreground">{{ __('less :amt disc', ['amt' => number_format($line->line_discount_cents / 100, 2)]) }}</div>
                                @endif
                            </td>
                        @endif
                        @if ($fieldVisibility['tax_column'])
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ optional($line->taxCode)->code }}
                                @if ($line->secondaryTaxCode)
                                    <span class="block">{{ $line->secondaryTaxCode->code }}</span>
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
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($invoice->subtotal_cents / 100, 2) }}</td>
                </tr>
                @php
                    $taxRows = \App\Support\Tax\LineTaxBreakdown::forLines($invoice->lines);
                @endphp
                @forelse ($taxRows as $taxRow)
                    <tr data-test="invoice-tax-row">
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-medium">{{ __('Tax') }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($invoice->tax_cents / 100, 2) }}</td>
                    </tr>
                @endforelse
                <tr class="text-base">
                    <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-semibold">
                        {{ __('Total') }}
                        @if ($invoice->currency_code && ! $company->isHomeCurrency($invoice->currency_code))
                            <span class="font-normal text-muted-foreground">{{ $invoice->currency_code }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($invoice->total_cents / 100, 2) }}</td>
                </tr>
                @if ($invoice->currency_code && ! $company->isHomeCurrency($invoice->currency_code) && $invoice->home_total_cents)
                    <tr>
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right text-xs text-muted-foreground">{{ __('Home equivalent (:code @ :rate)', ['code' => $company->currency_code, 'rate' => rtrim(rtrim((string) $invoice->fx_rate, '0'), '.')]) }}</td>
                        <td class="px-4 py-2 text-right font-mono text-xs text-muted-foreground">{{ number_format($invoice->home_total_cents / 100, 2) }}</td>
                    </tr>
                @endif
                <tr>
                    <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-medium">{{ __('Paid') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($invoice->amount_paid_cents / 100, 2) }}</td>
                </tr>
                <tr class="text-base">
                    <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-semibold">{{ __('Balance due') }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($invoice->balanceCents() / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Milestone / deposit payment schedule --}}
    @if ($invoice->status !== InvoiceStatus::Void)
        <div class="mt-6 rounded-lg border border-border" data-test="payment-schedule">
            <div class="flex items-center justify-between border-b border-border bg-muted px-4 py-2">
                <span class="font-medium">{{ __('Payment schedule') }}</span>
                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="$toggle('editingSchedule')" data-test="edit-schedule">{{ __('Edit') }}</flux:button>
            </div>

            @if (! $editingSchedule)
                @if ($this->paymentSchedule->isNotEmpty())
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-border">
                            @foreach ($this->paymentSchedule as $row)
                                <tr data-test="schedule-row">
                                    <td class="px-4 py-2">{{ $row['request']->label }}</td>
                                    <td class="px-4 py-2 text-muted-foreground">{{ $row['request']->due_date?->toDateString() }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ number_format($row['request']->amount_cents / 100, 2) }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <flux:badge size="sm" :color="$row['status'] === \App\Enums\PaymentRequestStatus::Paid ? 'green' : ($row['status'] === \App\Enums\PaymentRequestStatus::Cancelled ? 'zinc' : 'amber')">{{ $row['status']->label() }}</flux:badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="px-4 py-4 text-sm text-muted-foreground">{{ __('No payment schedule. Add deposit or progress milestones for this invoice.') }}</div>
                @endif
            @else
                <div class="space-y-3 p-4">
                    @error('milestones') <flux:text class="text-red-600" data-test="schedule-error">{{ $message }}</flux:text> @enderror
                    @foreach ($milestones as $i => $milestone)
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-12" data-test="milestone-edit-row" wire:key="milestone-{{ $i }}">
                            <flux:input wire:model="milestones.{{ $i }}.label" :placeholder="__('e.g. 50% deposit')" class="sm:col-span-4" />
                            <flux:select wire:model.live="milestones.{{ $i }}.type" class="sm:col-span-3">
                                <flux:select.option value="percent">{{ __('Percentage') }}</flux:select.option>
                                <flux:select.option value="fixed">{{ __('Fixed amount') }}</flux:select.option>
                            </flux:select>
                            <flux:input wire:model="milestones.{{ $i }}.value" :placeholder="$milestone['type'] === 'percent' ? '50' : '0.00'" class="sm:col-span-2" />
                            <flux:input type="date" wire:model="milestones.{{ $i }}.due_date" class="sm:col-span-2" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeMilestone({{ $i }})" class="sm:col-span-1" data-test="remove-milestone" />
                        </div>
                    @endforeach
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="addMilestone" data-test="add-milestone">{{ __('Add milestone') }}</flux:button>
                        <flux:button size="sm" variant="primary" wire:click="savePaymentSchedule" data-test="save-schedule">{{ __('Save schedule') }}</flux:button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    @php
        $taxRegistrations = $invoice->lines
            ->flatMap(fn ($line) => [optional($line->taxCode)->agency, optional($line->secondaryTaxCode)->agency])
            ->filter(fn ($agency) => $agency && filled($agency->registration_number))
            ->unique('id')
            ->values();
    @endphp

    @if ($taxRegistrations->isNotEmpty())
        <div class="mt-4 space-y-1 text-sm text-muted-foreground" data-test="invoice-tax-registrations">
            @foreach ($taxRegistrations as $agency)
                <div>{{ $agency->name }}: <span class="font-mono">{{ $agency->registration_number }}</span></div>
            @endforeach
        </div>
    @endif

    @if ($invoice->memo)
        <flux:text class="mt-4 text-muted-foreground">{{ $invoice->memo }}</flux:text>
    @endif

    @if ($invoice->customer_message)
        <flux:text class="mt-2 text-muted-foreground" data-test="invoice-customer-message">{{ $invoice->customer_message }}</flux:text>
    @endif

    <div class="mt-6 space-y-3 rounded-lg border border-border p-4" data-test="invoice-attachments">
        <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

        @forelse ($this->attachments as $att)
            <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="att-{{ $att->id }}" data-test="invoice-attachment-row">
                <x-attachment-link :attachment="$att" :company="$company" />
                <flux:button variant="ghost" size="sm" icon="x-mark"
                    wire:click="removeAttachment({{ $att->id }})"
                    wire:confirm="{{ __('Remove this attachment?') }}"
                    data-test="invoice-attachment-remove" />
            </div>
        @empty
            <flux:text class="text-sm text-muted-foreground">{{ __('No attachments yet.') }}</flux:text>
        @endforelse

        <x-attachment-dropzone model="newAttachments"
            accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
            :description="__('PDF, images, or Office docs up to 10 MB each.')"
            data-test="invoice-attachment-input" />

        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        @if (count($newAttachments) > 0)
            <flux:button variant="filled" wire:click="uploadAttachments" data-test="invoice-attachment-upload">
                {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
            </flux:button>
        @endif
    </div>

    <flux:modal name="send-invoice" class="md:w-[32rem]">
        <form wire:submit="sendToClient" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $invoice->member_id ? __('Send invoice to member') : __('Send invoice to client') }}</flux:heading>
                <flux:subheading>{{ __('They get a one-click link to view and pay invoice :no, with the PDF attached.', ['no' => $invoice->invoice_no]) }}</flux:subheading>
            </div>

            @unless ($invoice->contact?->invoice_emails_enabled)
                <flux:callout icon="information-circle" data-test="send-invoice-opted-out">
                    {{ __('This customer has automated invoice emails turned off. Sending now will still reach them, and will not change that setting.') }}
                </flux:callout>
            @endunless

            @php($emailSettings = $company->invoiceSettingsOrNew())
            <flux:field>
                <flux:label>{{ __('Replies go to') }}</flux:label>
                <flux:input
                    type="text"
                    :value="$emailSettings->email_from_address ?: __('System default')"
                    readonly
                    variant="filled"
                    data-test="send-invoice-from"
                />
                <flux:description>
                    {{ __('Change this under') }}
                    <flux:link :href="route('settings.invoices', ['company' => $company])" wire:navigate>{{ __('Invoice settings') }}</flux:link>.
                </flux:description>
            </flux:field>

            <flux:input wire:model="sendToEmail" :label="__('To')" required :description="__('Separate multiple addresses with commas.')" data-test="send-invoice-to" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="sendCc" :label="__('CC')" data-test="send-invoice-cc" />
                <flux:input wire:model="sendBcc" :label="__('BCC')" data-test="send-invoice-bcc" />
            </div>

            <flux:checkbox wire:model="sendCcSelf" :label="__('CC my business email')" data-test="send-invoice-cc-self" />

            <flux:textarea wire:model="sendMessage" :label="__('Message')" rows="5" data-test="send-invoice-message" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" icon="paper-airplane" data-test="send-invoice-submit">
                    {{ __('Send') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
