<?php

namespace App\Actions\Documents;

use App\Actions\Purchasing\SaveBill;
use App\Actions\Purchasing\SaveExpense;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillType;
use App\Enums\InboxItemStatus;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\InboxItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Promotes a reviewed inbox item into a DRAFT bill or expense. Framework-
 * agnostic: validates the write gate, maps the item's (possibly user-corrected)
 * extracted data onto the matching Save action's `$data` shape, creates the
 * draft (never posts — the user reviews and posts the document afterwards),
 * re-points the source Attachment from the inbox item onto the new document, and
 * marks the item promoted.
 *
 * The review screen passes corrected values through $overrides, which always win
 * over the OCR-extracted figures:
 *   document_type:      'bill'|'expense'|'reimbursement'  (default: the item's suggested type)
 *   contact_id:         ?int   (bill: vendor; reimbursement: employee/owner — required)
 *   payment_account_id: ?int   (expense: bank/credit-card account paid from)
 *   account_id:         ?int   (default line category/expense account)
 *   vendor / amount_cents / currency / date: scalar overrides of `extracted`
 *   lines: ?list<array{account_id?: ?int, description?: ?string, amount_cents?: ?int,
 *                      tax_code_id?: ?int, secondary_tax_code_id?: ?int,
 *                      tax_override_cents?: ?int, secondary_tax_override_cents?: ?int}>
 *
 * A 'reimbursement' is a Bill with bill_type=reimbursement owed to an employee
 * contact; it reuses promoteToBill and posts to Employee Reimbursements Payable.
 */
final class PromoteInboxItem
{
    public function __construct(
        protected SaveBill $saveBill,
        protected SaveExpense $saveExpense,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function handle(InboxItem $item, array $overrides = []): Model
    {
        if ($item->status === InboxItemStatus::Promoted) {
            throw new RuntimeException('This inbox item has already been promoted.');
        }

        return DB::transaction(function () use ($item, $overrides): Model {
            $extracted = (array) ($item->extracted ?? []);

            $vendor = $overrides['vendor'] ?? ($extracted['vendor'] ?? null);
            $amountCents = (int) ($overrides['amount_cents'] ?? ($extracted['amount_cents'] ?? 0));
            $currency = $overrides['currency'] ?? ($extracted['currency'] ?? null);
            $date = $overrides['date'] ?? ($extracted['date'] ?? null) ?? now()->toDateString();
            $lines = $this->resolveLines($overrides, $extracted, $amountCents);

            $type = (string) ($overrides['document_type'] ?? $item->suggested_document_type ?? 'bill');

            $document = match ($type) {
                'expense' => $this->promoteToExpense($item, $overrides, $date, $lines),
                'reimbursement' => $this->promoteToBill($item, $overrides, $date, $currency, $lines, BillType::Reimbursement),
                default => $this->promoteToBill($item, $overrides, $date, $currency, $lines, BillType::Vendor),
            };

            $this->carryAttachment($item, $document);

            $item->forceFill([
                'status' => InboxItemStatus::Promoted->value,
                'promoted_document_type' => $type,
                'promoted_document_id' => $document->getKey(),
            ])->save();

            return $document;
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<array{account_id: int, description: ?string, amount_cents: int, tax_code_id: ?int, secondary_tax_code_id: ?int, tax_override_cents: ?int, secondary_tax_override_cents: ?int}>  $lines
     */
    private function promoteToBill(InboxItem $item, array $overrides, string $date, ?string $currency, array $lines, BillType $billType): Model
    {
        $contactId = $overrides['contact_id'] ?? $item->suggested_contact_id;

        if ($contactId === null) {
            throw new RuntimeException($billType === BillType::Reimbursement
                ? 'An employee is required to create a reimbursement from this document.'
                : 'A vendor is required to create a bill from this document.');
        }

        $data = [
            'contact_id' => (int) $contactId,
            'bill_type' => $billType->value,
            'bill_date' => $date,
            'currency_code' => $currency,
            'lines' => array_map(fn (array $l): array => [
                'account_id' => $l['account_id'],
                'description' => $l['description'],
                'quantity' => 1,
                'unit_price_cents' => $l['amount_cents'],
                'tax_code_id' => $l['tax_code_id'],
                'secondary_tax_code_id' => $l['secondary_tax_code_id'],
                'tax_override_cents' => $l['tax_override_cents'],
                'secondary_tax_override_cents' => $l['secondary_tax_override_cents'],
            ], $lines),
        ];

        return $this->saveBill->handle($data);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<array{account_id: int, description: ?string, amount_cents: int, tax_code_id: ?int, secondary_tax_code_id: ?int, tax_override_cents: ?int, secondary_tax_override_cents: ?int}>  $lines
     */
    private function promoteToExpense(InboxItem $item, array $overrides, string $date, array $lines): Model
    {
        $paymentAccountId = $overrides['payment_account_id'] ?? $this->defaultPaymentAccount()?->id;

        if ($paymentAccountId === null) {
            throw new RuntimeException('A payment account is required to create an expense from this document.');
        }

        $contactId = $overrides['contact_id'] ?? $item->suggested_contact_id;

        // An expense requires a non-null payee name. Prefer the (corrected) vendor,
        // then the OCR'd vendor; SaveExpense fills from the contact when those are
        // blank, so only fall back to a placeholder when there is no contact either.
        $payeeName = $overrides['vendor'] ?? data_get($item->extracted, 'vendor');
        if (($payeeName === null || $payeeName === '') && $contactId === null) {
            $payeeName = 'Expense';
        }

        $data = [
            'payment_account_id' => (int) $paymentAccountId,
            'expense_date' => $date,
            'payee_contact_id' => $contactId !== null ? (int) $contactId : null,
            'payee_name' => $payeeName,
            'lines' => array_map(fn (array $l): array => [
                'account_id' => $l['account_id'],
                'description' => $l['description'],
                'amount_cents' => $l['amount_cents'],
                'tax_code_id' => $l['tax_code_id'],
                'secondary_tax_code_id' => $l['secondary_tax_code_id'],
                'tax_override_cents' => $l['tax_override_cents'],
                'secondary_tax_override_cents' => $l['secondary_tax_override_cents'],
            ], $lines),
        ];

        return $this->saveExpense->handle($data);
    }

    /**
     * Resolve the document's lines. Prefer explicit per-line overrides (which the
     * review screen sends with a per-line account + tax codes), then the extracted
     * line items; fall back to a single line for the grand total. Each line gets a
     * category account (per-line override → document override → default) and any
     * tax codes/overrides the review screen attached.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $extracted
     * @return list<array{account_id: int, description: ?string, amount_cents: int, tax_code_id: ?int, secondary_tax_code_id: ?int, tax_override_cents: ?int, secondary_tax_override_cents: ?int}>
     */
    private function resolveLines(array $overrides, array $extracted, int $amountCents): array
    {
        $defaultAccountId = (int) ($overrides['account_id'] ?? $this->defaultExpenseAccount()->id);

        $source = $overrides['lines'] ?? ($extracted['line_items'] ?? []);
        $lines = [];

        foreach ((array) $source as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cents = (int) ($row['amount_cents'] ?? 0);

            if ($cents === 0) {
                continue;
            }

            $lines[] = $this->lineRow(
                (int) ($row['account_id'] ?? $defaultAccountId),
                isset($row['description']) ? (string) $row['description'] : null,
                $cents,
                $row,
            );
        }

        if ($lines === []) {
            $lines[] = $this->lineRow($defaultAccountId, $extracted['vendor'] ?? null, $amountCents, []);
        }

        return $lines;
    }

    /**
     * Normalise a single resolved line, carrying the optional tax codes/overrides
     * straight through to SaveBill/SaveExpense (which already understand them).
     *
     * @param  array<string, mixed>  $row
     * @return array{account_id: int, description: ?string, amount_cents: int, tax_code_id: ?int, secondary_tax_code_id: ?int, tax_override_cents: ?int, secondary_tax_override_cents: ?int}
     */
    private function lineRow(int $accountId, ?string $description, int $amountCents, array $row): array
    {
        return [
            'account_id' => $accountId,
            'description' => $description,
            'amount_cents' => $amountCents,
            'tax_code_id' => isset($row['tax_code_id']) ? (int) $row['tax_code_id'] : null,
            'secondary_tax_code_id' => isset($row['secondary_tax_code_id']) ? (int) $row['secondary_tax_code_id'] : null,
            'tax_override_cents' => isset($row['tax_override_cents']) ? (int) $row['tax_override_cents'] : null,
            'secondary_tax_override_cents' => isset($row['secondary_tax_override_cents']) ? (int) $row['secondary_tax_override_cents'] : null,
        ];
    }

    /**
     * Re-point the OCR'd source file from the inbox item onto the created
     * document, so it shows in the document's attachments. The inbox item keeps
     * its reference for traceability.
     */
    private function carryAttachment(InboxItem $item, Model $document): void
    {
        if ($item->attachment_id === null) {
            return;
        }

        $attachment = Attachment::query()->find($item->attachment_id);

        if ($attachment === null) {
            return;
        }

        $attachment->forceFill([
            'attachable_type' => $document->getMorphClass(),
            'attachable_id' => $document->getKey(),
        ])->save();
    }

    private function defaultExpenseAccount(): Account
    {
        $account = Account::query()
            ->selectableForItemAccount()
            ->where('type', AccountType::Expense->value)
            ->orderBy('code')
            ->first();

        if ($account === null) {
            throw new RuntimeException('No expense account is available to categorize this document.');
        }

        return $account;
    }

    private function defaultPaymentAccount(): ?Account
    {
        // The accounts an expense can be paid from — bank, credit card, or cash
        // (current asset), matching the inbox review form's "Paid from" options.
        // Ordered by code, so a bank/asset is preferred over a credit-card liability.
        return Account::query()
            ->whereIn('subtype', [
                AccountSubtype::Bank->value,
                AccountSubtype::CreditCard->value,
                AccountSubtype::CurrentAsset->value,
            ])
            ->where('is_active', true)
            ->orderBy('code')
            ->first();
    }
}
