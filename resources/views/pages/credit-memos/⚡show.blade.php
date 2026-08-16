<?php

use App\Enums\AccountSubtype;
use App\Enums\CreditMemoStatus;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\InvoiceSetting;
use App\Models\PaymentMethod;
use App\Services\AttachmentService;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\ReceiptPoster;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Credit memo')] class extends Component {
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

    public bool $showRefundModal = false;

    /** @var 'cheque'|'card' */
    public string $refundMethod = 'cheque';

    public string $refundAmount = '';

    public string $refundDate = '';

    public ?int $refundBankAccountId = null;

    public ?int $refundPaymentMethodId = null;

    public ?int $refundDepositToAccountId = null;

    public string $refundReference = '';

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

    public function deleteRefundCheque(int $chequeId, ChequePoster $poster): void
    {
        $cheque = $this->creditMemo->refundCheques()->find($chequeId);

        if (! $cheque) {
            return;
        }

        if ($cheque->journal_entry_id) {
            try {
                $poster->void($cheque);
            } catch (\RuntimeException $e) {
                Flux::toast(variant: 'danger', text: $e->getMessage());

                return;
            }
        } else {
            $cheque->lines()->delete();
            $cheque->delete();
        }

        $this->afterRefundRemoved();
        Flux::toast(variant: 'success', text: __('Refund removed.'));
    }

    public function deleteRefundReceipt(int $receiptId, ReceiptPoster $poster): void
    {
        $receipt = $this->creditMemo->refundReceipts()->find($receiptId);

        if (! $receipt) {
            return;
        }

        try {
            $poster->void($receipt);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->afterRefundRemoved();
        Flux::toast(variant: 'success', text: __('Refund removed.'));
    }

    protected function afterRefundRemoved(): void
    {
        unset($this->refundCheques, $this->refundReceipts, $this->canRefund);
        $this->creditMemo->refresh();
    }

    #[Computed]
    public function canRefund(): bool
    {
        return $this->creditMemo->status === CreditMemoStatus::Posted
            && $this->creditMemo->remainingRefundableCents() > 0;
    }

    #[Computed]
    public function refundCheques()
    {
        return $this->creditMemo->refundCheques()->where('status', '!=', 'void')->orderBy('id')->get();
    }

    #[Computed]
    public function refundReceipts()
    {
        return $this->creditMemo->refundReceipts()->where('status', '!=', 'void')->orderBy('id')->get();
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function depositAccounts()
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::UndepositedFunds->value, AccountSubtype::Bank->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function paymentMethods()
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function openRefund(): void
    {
        if (! $this->canRefund) {
            return;
        }

        $this->resetErrorBag();
        $this->refundMethod = 'cheque';
        $this->refundAmount = Money::fromCents($this->creditMemo->remainingRefundableCents())->toDecimalString();
        $this->refundDate = $this->company->currentDateTime()->toDateString();
        $this->refundReference = '';
        $this->refundBankAccountId = $this->bankAccounts->first()?->id;
        $this->refundPaymentMethodId = $this->paymentMethods->first()?->id;
        $this->refundDepositToAccountId = Account::query()
            ->where('subtype', AccountSubtype::UndepositedFunds->value)
            ->where('is_active', true)
            ->value('id');

        $this->showRefundModal = true;
    }

    public function submitRefund(ReceiptPoster $poster): void
    {
        if (! $this->canRefund) {
            return;
        }

        $this->validate([
            'refundMethod' => ['required', 'in:cheque,card'],
            'refundAmount' => ['required', 'string'],
            'refundDate' => ['required', 'date'],
        ]);

        try {
            $amountCents = Money::fromString($this->refundAmount)->cents;
        } catch (\Throwable) {
            $this->addError('refundAmount', __('Enter a valid amount.'));

            return;
        }

        $remaining = $this->creditMemo->remainingRefundableCents();

        if ($amountCents <= 0) {
            $this->addError('refundAmount', __('Refund amount must be greater than zero.'));

            return;
        }

        if ($amountCents > $remaining) {
            $this->addError('refundAmount', __('Refund cannot exceed the remaining :amount.', ['amount' => Money::fromCents($remaining)->toDecimalString()]));

            return;
        }

        if ($this->refundMethod === 'cheque') {
            $this->refundByCheque($amountCents);

            return;
        }

        $this->refundByCard($poster, $amountCents);
    }

    protected function refundByCheque(int $amountCents): void
    {
        $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->find($this->refundBankAccountId);

        if (! $bank) {
            $this->addError('refundBankAccountId', __('Select a bank account.'));

            return;
        }

        $ar = $this->systemAccount(AccountSubtype::AccountsReceivable);

        if (! $ar) {
            Flux::toast(variant: 'danger', text: __('No Accounts Receivable control account is configured.'));

            return;
        }

        $cheque = Cheque::create([
            'bank_account_id' => $bank->id,
            'cheque_no' => $this->nextChequeNumber($bank->id),
            'cheque_date' => $this->refundDate,
            'payee_contact_id' => $this->creditMemo->contact_id,
            'credit_memo_id' => $this->creditMemo->id,
            'payee_name' => $this->creditMemo->contact->display_name,
            'memo' => __('Refund of credit memo :no', ['no' => $this->creditMemo->credit_memo_no]),
        ]);

        $cheque->lines()->create([
            'account_id' => $ar->id,
            'description' => __('Refund of credit memo :no', ['no' => $this->creditMemo->credit_memo_no]),
            'amount_cents' => $amountCents,
            'line_order' => 0,
        ]);

        $cheque->recalculateAmount();

        Flux::toast(text: __('Draft refund cheque created — review and post it.'));
        $this->redirectRoute('cheques.edit', ['company' => $this->company->slug, 'cheque' => $cheque->id], navigate: true);
    }

    protected function refundByCard(ReceiptPoster $poster, int $amountCents): void
    {
        $depositTo = Account::query()
            ->whereIn('subtype', [AccountSubtype::UndepositedFunds->value, AccountSubtype::Bank->value])
            ->find($this->refundDepositToAccountId);

        if (! $depositTo) {
            $this->addError('refundDepositToAccountId', __('Select an account to refund from.'));

            return;
        }

        $receipt = CustomerReceipt::create([
            'contact_id' => $this->creditMemo->contact_id,
            'credit_memo_id' => $this->creditMemo->id,
            'receipt_no' => app(DocumentNumberGenerator::class)->next($this->company, CustomerReceipt::class, 'receipt_no', 'REC'),
            'receipt_date' => $this->refundDate,
            'deposit_to_account_id' => $depositTo->id,
            'payment_method_id' => $this->refundPaymentMethodId ?: null,
            'reference' => $this->refundReference ?: null,
            'amount_cents' => -$amountCents,
            'memo' => __('Refund of credit memo :no', ['no' => $this->creditMemo->credit_memo_no]),
        ]);

        try {
            $poster->post($receipt);
        } catch (\RuntimeException $e) {
            $receipt->delete();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->showRefundModal = false;
        unset($this->refundReceipts, $this->canRefund);
        $this->creditMemo->refresh();

        Flux::toast(variant: 'success', text: __('Credit-card refund recorded. Deposit it with your other card receipts.'));
    }

    protected function systemAccount(AccountSubtype $subtype): ?Account
    {
        return Account::query()
            ->where('subtype', $subtype->value)
            ->where('is_system', true)
            ->first();
    }

    protected function nextChequeNumber(int $bankAccountId): string
    {
        $last = Cheque::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('bank_account_id', $bankAccountId)
            ->orderByDesc('id')
            ->first();

        if ($last && is_numeric($last->cheque_no)) {
            return (string) (((int) $last->cheque_no) + 1);
        }

        return '1001';
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
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($creditMemo->subtotal_cents / 100, 2) }}</td>
                </tr>
                @php
                    $taxRows = \App\Support\Tax\LineTaxBreakdown::forLines($creditMemo->lines);
                @endphp
                @forelse ($taxRows as $taxRow)
                    <tr data-test="credit-memo-tax-row">
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
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
                <div>{{ $agency->name }}: <span class="font-mono">{{ $agency->registration_number }}</span></div>
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

            @foreach ($this->refundCheques as $cheque)
                <div class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm" wire:key="rc-{{ $cheque->id }}">
                    <a href="{{ route($cheque->journal_entry_id ? 'cheques.show' : 'cheques.edit', ['company' => $company->slug, 'cheque' => $cheque->id]) }}" wire:navigate class="flex items-center gap-2 hover:underline">
                        <flux:icon name="banknotes" class="size-4 text-muted-foreground" />
                        <span>{{ __('Cheque') }} {{ $cheque->cheque_no }}</span>
                        <flux:badge size="sm" :color="$cheque->journal_entry_id ? 'blue' : 'amber'">
                            {{ $cheque->journal_entry_id ? __('Posted') : __('Draft') }}
                        </flux:badge>
                    </a>
                    <div class="flex items-center gap-2">
                        <span class="font-mono">{{ number_format($cheque->amount_cents / 100, 2) }}</span>
                        <flux:button variant="ghost" size="sm" icon="x-mark"
                            wire:click="deleteRefundCheque({{ $cheque->id }})"
                            wire:confirm="{{ $cheque->journal_entry_id
                                ? __('Void this refund cheque? A reversing GL entry will be posted.')
                                : __('Delete this draft refund cheque?') }}"
                            data-test="delete-refund-cheque" />
                    </div>
                </div>
            @endforeach

            @foreach ($this->refundReceipts as $receipt)
                <div class="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm" wire:key="rr-{{ $receipt->id }}">
                    <a href="{{ route('receipts.show', ['company' => $company->slug, 'receipt' => $receipt->id]) }}" wire:navigate class="flex items-center gap-2 hover:underline">
                        <flux:icon name="credit-card" class="size-4 text-muted-foreground" />
                        <span>{{ __('Card refund') }} {{ $receipt->receipt_no }}</span>
                    </a>
                    <div class="flex items-center gap-2">
                        <span class="font-mono">{{ number_format(abs($receipt->amount_cents) / 100, 2) }}</span>
                        <flux:button variant="ghost" size="sm" icon="x-mark"
                            wire:click="deleteRefundReceipt({{ $receipt->id }})"
                            wire:confirm="{{ __('Void this card refund? A reversing GL entry will be posted.') }}"
                            data-test="delete-refund-receipt" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <flux:modal wire:model.self="showRefundModal" class="md:w-[32rem]">
        <form wire:submit="submitRefund" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Refund to client') }}</flux:heading>
                <flux:subheading>{{ $creditMemo->contact->display_name }}</flux:subheading>
            </div>

            <flux:radio.group wire:model.live="refundMethod" :label="__('Method')" variant="segmented">
                <flux:radio value="cheque" :label="__('By cheque')" />
                <flux:radio value="card" :label="__('By credit card')" />
            </flux:radio.group>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="refundAmount" :label="__('Amount')" class="text-right" data-test="refund-amount-input" />
                <flux:input type="date" wire:model="refundDate" :label="__('Date')" />
            </div>

            @if ($refundMethod === 'cheque')
                <flux:select wire:model="refundBankAccountId" :label="__('Bank account')" data-test="refund-bank-select">
                    @foreach ($this->bankAccounts as $opt)
                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:text class="text-sm text-muted-foreground">{{ __('A draft cheque is created (debits Accounts Receivable, credits the bank). Review and post it to print.') }}</flux:text>
            @else
                <flux:select wire:model="refundDepositToAccountId" :label="__('Refund from')" data-test="refund-deposit-select">
                    @foreach ($this->depositAccounts as $opt)
                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="refundPaymentMethodId" :label="__('Payment method')">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->paymentMethods as $opt)
                        <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="refundReference" :label="__('Reference (optional)')" />
                <flux:text class="text-sm text-muted-foreground">{{ __('Records a negative receipt to Undeposited Funds. Deposit it with your other card receipts.') }}</flux:text>
            @endif

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" type="button" wire:click="$set('showRefundModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="submit-refund-button">
                    {{ $refundMethod === 'cheque' ? __('Create draft cheque') : __('Record refund') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

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
