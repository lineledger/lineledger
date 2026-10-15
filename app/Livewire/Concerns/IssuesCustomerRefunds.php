<?php

namespace App\Livewire\Concerns;

use App\Actions\Sales\RecordCustomerRefund;
use App\Enums\AccountSubtype;
use App\Enums\RefundMethod;
use App\Exceptions\EditLocks\RecordEditLockedException;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\ReceiptPoster;
use App\Support\Money;
use Closure;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use RuntimeException;
use Throwable;

/**
 * "Refund to client" on a show page: pays customer credit back by cheque or by
 * card through {@see RecordCustomerRefund}, and removes a refund again. Used by
 * the credit memo page (its own credit), the payment page (its unapplied
 * credit) and the invoice page (the customer's overpayments).
 *
 * The page provides:
 *   - eligibleRefundSources(): the credit memos or payments that can be
 *     refunded from this page right now. The modal's source is only ever picked
 *     from this list, never straight from the id the browser sends.
 *   - removableRefundCheques() / removableRefundReceipts(): the refunds its
 *     remove buttons may touch.
 *   - refundInvoiceContext(): the invoice a refund is issued from, if any.
 *
 * The public actions (submitRefund, deleteRefundCheque, deleteRefundReceipt)
 * are declared on each page and call processRefund() / removeRefundCheque() /
 * removeRefundReceipt(). Only the page knows whether #[GuardsEditLock] fits:
 * it re-versions the page's own record, which is right for a credit memo or
 * payment whose credit the refund spends, but wrong for an invoice that the
 * refund leaves untouched. A source that isn't the page's record is guarded
 * here instead.
 */
trait IssuesCustomerRefunds
{
    public bool $showRefundModal = false;

    /** @var 'cheque'|'card' */
    public string $refundMethod = 'cheque';

    public string $refundAmount = '';

    public string $refundDate = '';

    public ?int $refundBankAccountId = null;

    public ?int $refundPaymentMethodId = null;

    public ?int $refundDepositToAccountId = null;

    public string $refundReference = '';

    public ?int $refundSourceId = null;

    /**
     * Where each RecordCustomerRefund error (keyed by API field) shows on the form.
     */
    private const REFUND_ERROR_FIELDS = [
        'method' => 'refundMethod',
        'amount_cents' => 'refundAmount',
        'refund_date' => 'refundDate',
        'bank_account_id' => 'refundBankAccountId',
        'deposit_to_account_id' => 'refundDepositToAccountId',
    ];

    /**
     * The credit memos or payments this page can refund now, each with credit left.
     *
     * @return Collection<int, CreditMemo|CustomerReceipt>
     */
    abstract protected function eligibleRefundSources(): Collection;

    /**
     * @return Builder<Cheque>|HasMany<Cheque, *>
     */
    abstract protected function removableRefundCheques(): Builder|HasMany;

    /**
     * @return Builder<CustomerReceipt>|HasMany<CustomerReceipt, *>
     */
    abstract protected function removableRefundReceipts(): Builder|HasMany;

    /**
     * The invoice a refund from this page is issued from, so it lists the refund.
     */
    protected function refundInvoiceContext(): ?Invoice
    {
        return null;
    }

    /**
     * Refresh whatever the page shows about refunds after one is added or removed.
     */
    protected function afterRefundChanged(): void {}

    /**
     * @return Collection<int, CreditMemo|CustomerReceipt>
     */
    #[Computed]
    public function refundSources(): Collection
    {
        return $this->eligibleRefundSources()->values();
    }

    #[Computed]
    public function canRefund(): bool
    {
        return $this->refundSources->isNotEmpty();
    }

    #[Computed]
    public function selectedRefundSource(): CreditMemo|CustomerReceipt|null
    {
        return $this->refundSources->first(fn (Model $source) => (int) $source->getKey() === (int) $this->refundSourceId)
            ?? $this->refundSources->first();
    }

    /**
     * @return EloquentCollection<int, Account>
     */
    #[Computed]
    public function refundBankAccounts(): EloquentCollection
    {
        return Account::query()
            ->where('subtype', AccountSubtype::Bank->value)
            ->where('is_active', true)
            ->whereNull('currency_code')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * @return EloquentCollection<int, Account>
     */
    #[Computed]
    public function refundDepositAccounts(): EloquentCollection
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::UndepositedFunds->value, AccountSubtype::Bank->value])
            ->where('is_active', true)
            ->whereNull('currency_code')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * @return EloquentCollection<int, PaymentMethod>
     */
    #[Computed]
    public function refundPaymentMethods(): EloquentCollection
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function openRefund(): void
    {
        $source = $this->refundSources->first();

        if ($source === null) {
            return;
        }

        $this->resetErrorBag();
        $this->refundSourceId = (int) $source->getKey();
        $this->refundMethod = RefundMethod::Cheque->value;
        $this->refundAmount = Money::fromCents($source->remainingRefundableCents())->toDecimalString();
        $this->refundDate = $this->company->currentDateTime()->toDateString();
        $this->refundReference = '';
        $this->refundBankAccountId = $this->refundBankAccounts->first()?->id;
        // A card refund usually goes back through the card terminal.
        $this->refundPaymentMethodId = ($this->refundPaymentMethods->first(fn (PaymentMethod $m) => str_contains(mb_strtolower($m->name), 'card'))
            ?? $this->refundPaymentMethods->first())?->id;
        $this->refundDepositToAccountId = Account::query()
            ->where('subtype', AccountSubtype::UndepositedFunds->value)
            ->where('is_active', true)
            ->whereNull('currency_code')
            ->value('id');

        unset($this->selectedRefundSource);
        $this->showRefundModal = true;
    }

    /**
     * Picking another payment in the modal resets the amount to what it holds.
     */
    public function updatedRefundSourceId(): void
    {
        unset($this->selectedRefundSource);

        $source = $this->selectedRefundSource;

        if ($source !== null) {
            $this->refundSourceId = (int) $source->getKey();
            $this->refundAmount = Money::fromCents($source->remainingRefundableCents())->toDecimalString();
        }
    }

    protected function processRefund(RecordCustomerRefund $action): void
    {
        $source = $this->selectedRefundSource;

        if ($source === null) {
            return;
        }

        $this->validate([
            'refundMethod' => ['required', Rule::enum(RefundMethod::class)],
            'refundAmount' => ['required', 'string'],
            'refundDate' => ['required', 'date'],
        ]);

        try {
            $amountCents = Money::fromString($this->refundAmount)->cents;
        } catch (Throwable) {
            $this->addError('refundAmount', __('Enter a valid amount.'));

            return;
        }

        $method = RefundMethod::from($this->refundMethod);

        $data = [
            'method' => $method,
            'amount_cents' => $amountCents,
            'refund_date' => $this->refundDate,
            'bank_account_id' => $this->refundBankAccountId,
            'deposit_to_account_id' => $this->refundDepositToAccountId,
            'payment_method_id' => $this->refundPaymentMethodId,
            'reference' => $this->refundReference,
        ];

        try {
            $refund = $this->guardRefundSource(
                $source,
                fn () => $action->handle($source, $data, $this->refundInvoiceContext()),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $property = self::REFUND_ERROR_FIELDS[$field] ?? null;

                $property !== null
                    ? $this->addError($property, $messages[0])
                    : Flux::toast(variant: 'danger', text: $messages[0]);
            }

            return;
        } catch (RecordEditLockedException|RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        if ($refund instanceof Cheque) {
            Flux::toast(text: __('Draft refund cheque created — review and post it.'));
            $this->redirectRoute('cheques.edit', ['company' => $this->company->slug, 'cheque' => $refund->id], navigate: true);

            return;
        }

        $this->showRefundModal = false;
        $this->refundChanged();

        Flux::toast(variant: 'success', text: __('Credit-card refund recorded. Deposit it with your other card receipts.'));
    }

    /**
     * Remove a refund cheque: void it if posted, otherwise delete the draft.
     * Either way its credit is refundable again.
     */
    protected function removeRefundCheque(int $chequeId, ChequePoster $poster): void
    {
        $cheque = $this->removableRefundCheques()->find($chequeId);

        if (! $cheque instanceof Cheque || ! $this->refundEditable($cheque)) {
            return;
        }

        try {
            $this->guardRefundSource($cheque->refundSource(), function () use ($cheque, $poster): void {
                if ($cheque->journal_entry_id) {
                    $poster->void($cheque);
                } else {
                    $cheque->lines()->delete();
                    $cheque->delete();
                }
            });
        } catch (RecordEditLockedException|RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->refundChanged();
        Flux::toast(variant: 'success', text: __('Refund removed.'));
    }

    /**
     * Remove a card refund by voiding it (a reversing entry), so its credit is
     * refundable again.
     */
    protected function removeRefundReceipt(int $receiptId, ReceiptPoster $poster): void
    {
        $receipt = $this->removableRefundReceipts()->find($receiptId);

        if (! $receipt instanceof CustomerReceipt || ! $this->refundEditable($receipt)) {
            return;
        }

        try {
            $this->guardRefundSource($receipt->refundSource(), fn () => $poster->void($receipt));
        } catch (RecordEditLockedException|RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->refundChanged();
        Flux::toast(variant: 'success', text: __('Refund removed.'));
    }

    /**
     * The refund cheque or receipt has its own edit lock: refuse removing one
     * someone has open, and invalidate an editor that opened it earlier.
     */
    protected function refundEditable(Model $refund): bool
    {
        $locks = app(EditLockManager::class);

        try {
            $locks->assertWritable($refund, Auth::user());
        } catch (RecordEditLockedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return false;
        }

        $locks->touch($refund);

        return true;
    }

    /**
     * Run a write that spends or restores a source's credit under the source's
     * edit lock — unless the source is this page's own record, which the page's
     * #[GuardsEditLock] already covers.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function guardRefundSource(CreditMemo|CustomerReceipt|null $source, Closure $write): mixed
    {
        $pageRecord = $this->editLockRecord();

        if ($source === null || ($pageRecord instanceof Model && $pageRecord->is($source))) {
            return $write();
        }

        $user = Auth::user();

        return app(EditLockManager::class)->guardWrite($source, $user instanceof User ? $user : null, $write);
    }

    private function refundChanged(): void
    {
        unset($this->refundSources, $this->canRefund, $this->selectedRefundSource);

        $this->afterRefundChanged();
    }
}
