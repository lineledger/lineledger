<?php

namespace App\Actions\Sales;

use App\Actions\Banking\SaveCheque;
use App\Enums\AccountSubtype;
use App\Enums\CreditMemoStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Enums\RefundMethod;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Services\Posting\ControlAccountResolver;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\ReceiptPoster;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pays customer credit back to the customer: the credit a credit memo grants,
 * or a payment's unapplied (overpaid) remainder — e.g. a family that paid by
 * card and then again by cheque, refunded the card payment. Shared by the
 * credit memo, invoice and payment pages and the API.
 *
 *   By cheque — a DRAFT cheque: one line on Accounts Receivable for the
 *     customer (DR AR, CR Bank), payee and mailing address from the customer.
 *     The user reviews, prints and posts it from the cheque form.
 *   By card — a NEGATIVE customer receipt, posted here (DR AR, CR Undeposited
 *     Funds or Bank). Deposited with the other card receipts it nets into the
 *     batch, or makes a refund-only deposit of its own.
 *
 * Either way the refund is linked back to its source (credit_memo_id or
 * refunded_receipt_id), which caps it at the credit left, and to the invoice
 * it was issued from (from_invoice_id), which lists it.
 *
 * The source row is locked for the whole write so two refunds of the same
 * credit serialize. Input problems throw a ValidationException keyed by the
 * API field names; posting problems (closed period, …) throw from the poster.
 * Callers own edit locks — never call EditLockManager from inside here.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   method:                RefundMethod|string  ('cheque' | 'card')
 *   amount_cents:          int     (positive)
 *   refund_date:           string
 *   bank_account_id:       ?int    (cheque)
 *   cheque_no:             ?string (cheque; null → the next number on that account)
 *   deposit_to_account_id: ?int    (card: Undeposited Funds or a bank)
 *   payment_method_id:     ?int    (card)
 *   reference:             ?string (card)
 *   receipt_no:            ?string (card; null → auto-generated)
 *   memo:                  ?string (null → "Refund of …")
 */
final class RecordCustomerRefund
{
    public function __construct(
        private DocumentNumberGenerator $numbers,
        private ReceiptPoster $receiptPoster,
        private ControlAccountResolver $controlAccounts,
        private SaveCheque $saveCheque,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(CreditMemo|CustomerReceipt $source, array $data, ?Invoice $fromInvoice = null): Cheque|CustomerReceipt
    {
        $method = $data['method'] instanceof RefundMethod
            ? $data['method']
            : RefundMethod::tryFrom((string) ($data['method'] ?? ''));

        if ($method === null) {
            throw ValidationException::withMessages(['method' => __('Choose to refund by cheque or by credit card.')]);
        }

        $amountCents = (int) ($data['amount_cents'] ?? 0);

        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount_cents' => __('Refund amount must be greater than zero.')]);
        }

        $refundDate = $this->refundDate($data['refund_date'] ?? null);

        return DB::transaction(function () use ($source, $data, $fromInvoice, $method, $amountCents, $refundDate): Cheque|CustomerReceipt {
            $source = $this->lockSource($source);

            $this->assertRefundable($source, $refundDate, $fromInvoice);

            $remaining = $source->remainingRefundableCents();

            if ($amountCents > $remaining) {
                throw ValidationException::withMessages(['amount_cents' => __('Refund cannot exceed the remaining :amount.', [
                    'amount' => Money::fromCents($remaining)->toDecimalString(),
                ])]);
            }

            $memo = filled($data['memo'] ?? null) ? (string) $data['memo'] : $this->defaultMemo($source);

            return $method === RefundMethod::Cheque
                ? $this->byCheque($source, $data, $fromInvoice, $amountCents, $refundDate, $memo)
                : $this->byCard($source, $data, $fromInvoice, $amountCents, $refundDate, $memo);
        });
    }

    private function byCheque(CreditMemo|CustomerReceipt $source, array $data, ?Invoice $fromInvoice, int $amountCents, string $refundDate, string $memo): Cheque
    {
        $company = $this->company();

        $bank = Account::query()
            ->where('subtype', AccountSubtype::Bank->value)
            ->find($data['bank_account_id'] ?? null);

        if (! $bank) {
            throw ValidationException::withMessages(['bank_account_id' => __('Select a bank account.')]);
        }

        // The credit is in the home currency; a foreign bank would convert every
        // leg of the cheque at its own rate.
        if ($bank->isForeignCurrency()) {
            throw ValidationException::withMessages(['bank_account_id' => __('Refund from a bank account in :currency.', ['currency' => $company->currency_code])]);
        }

        $ar = $this->controlAccounts->resolve($company, AccountSubtype::AccountsReceivable);

        $cheque = $this->saveCheque->handle([
            'bank_account_id' => $bank->id,
            'cheque_no' => filled($data['cheque_no'] ?? null)
                ? (string) $data['cheque_no']
                : $this->numbers->nextChequeNumber($company, $bank->id),
            'cheque_date' => $refundDate,
            'payee_contact_id' => $source->contact_id,
            'memo' => $memo,
            'lines' => [[
                'account_id' => $ar->id,
                // The AR line belongs to the source's customer — the payee may be
                // someone else by the time the operator reviews the draft.
                'contact_id' => $source->contact_id,
                'description' => $memo,
                'amount_cents' => $amountCents,
            ]],
        ]);

        $cheque->forceFill([
            $source->refundLinkColumn() => $source->id,
            'from_invoice_id' => $fromInvoice?->id,
        ])->save();

        return $cheque->fresh();
    }

    private function byCard(CreditMemo|CustomerReceipt $source, array $data, ?Invoice $fromInvoice, int $amountCents, string $refundDate, string $memo): CustomerReceipt
    {
        $depositTo = Account::query()
            ->whereIn('subtype', [AccountSubtype::UndepositedFunds->value, AccountSubtype::Bank->value])
            ->find($data['deposit_to_account_id'] ?? null);

        if (! $depositTo || $depositTo->isForeignCurrency()) {
            throw ValidationException::withMessages(['deposit_to_account_id' => __('Select an account to refund from.')]);
        }

        $paymentMethodId = filled($data['payment_method_id'] ?? null)
            ? PaymentMethod::query()->whereKey((int) $data['payment_method_id'])->value('id')
            : null;

        $receipt = CustomerReceipt::create([
            'contact_id' => $source->contact_id,
            $source->refundLinkColumn() => $source->id,
            'from_invoice_id' => $fromInvoice?->id,
            'receipt_no' => filled($data['receipt_no'] ?? null)
                ? (string) $data['receipt_no']
                : $this->numbers->next($this->company(), CustomerReceipt::class, 'receipt_no', 'REC'),
            'receipt_date' => $refundDate,
            'deposit_to_account_id' => $depositTo->id,
            'payment_method_id' => $paymentMethodId,
            'reference' => filled($data['reference'] ?? null) ? (string) $data['reference'] : null,
            'amount_cents' => -$amountCents,
            'memo' => $memo,
            'status' => ReceiptStatus::Draft,
        ]);

        $this->receiptPoster->post($receipt);

        return $receipt->fresh(['applications']);
    }

    private function lockSource(CreditMemo|CustomerReceipt $source): CreditMemo|CustomerReceipt
    {
        return $source::query()->lockForUpdate()->findOrFail($source->getKey());
    }

    private function assertRefundable(CreditMemo|CustomerReceipt $source, string $refundDate, ?Invoice $fromInvoice): void
    {
        if ($source instanceof CreditMemo) {
            if ($source->status !== CreditMemoStatus::Posted) {
                throw ValidationException::withMessages(['credit_memo_id' => __('Only a posted credit memo can be refunded.')]);
            }
        } else {
            $this->assertRefundablePayment($source, $refundDate);
        }

        if ($fromInvoice === null) {
            return;
        }

        if ((int) $fromInvoice->contact_id !== (int) $source->contact_id) {
            throw ValidationException::withMessages(['refunded_receipt_id' => __('That payment belongs to a different customer than this invoice.')]);
        }

        if (! in_array($fromInvoice->status, [InvoiceStatus::Posted, InvoiceStatus::Partial, InvoiceStatus::Paid], true)) {
            throw ValidationException::withMessages(['from_invoice_id' => __('Only a posted invoice can issue a refund.')]);
        }
    }

    private function assertRefundablePayment(CustomerReceipt $payment, string $refundDate): void
    {
        $error = match (true) {
            $payment->status !== ReceiptStatus::Posted => __('Only a posted payment can be refunded.'),
            $payment->isRefund() || (int) $payment->amount_cents <= 0 => __('A refund can\'t itself be refunded.'),
            $payment->isForeignCurrency() => __('Refunding a foreign-currency payment isn\'t supported yet.'),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['refunded_receipt_id' => $error]);
        }

        $paidOn = $payment->receipt_date->toDateString();

        if ($refundDate < $paidOn) {
            throw ValidationException::withMessages(['refund_date' => __('A refund can\'t be dated before the payment it refunds (:date).', ['date' => $paidOn])]);
        }
    }

    private function refundDate(mixed $value): string
    {
        if (! filled($value)) {
            throw ValidationException::withMessages(['refund_date' => __('Enter the refund date.')]);
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages(['refund_date' => __('Enter a valid refund date.')]);
        }
    }

    private function defaultMemo(CreditMemo|CustomerReceipt $source): string
    {
        return $source instanceof CreditMemo
            ? __('Refund of credit memo :no', ['no' => $source->credit_memo_no])
            : __('Refund of overpayment on :no', ['no' => $source->receipt_no]);
    }

    private function company(): Company
    {
        return app('current_company');
    }
}
