<?php

namespace App\Actions\Banking;

use App\Actions\Purchasing\SaveBillPayment;
use App\Actions\Purchasing\SaveExpense;
use App\Enums\BillType;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Services\Banking\Import\OpenBillMatcher;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\ExpensePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single path that turns a reviewed bank statement line into a posted
 * transaction — shared by the import commit, the For Review feed, bulk
 * categorize and "Accept". What gets recorded depends on what the user chose:
 *
 *   outflow + payee + open bill(s) → one BillPayment applied to that bill / those bills
 *                                    (vendor bills, or an employee's expense-claim reimbursement)
 *   outflow + vendor              → an Expense to that vendor (pay-now purchase)
 *   outflow + tax code, no payee  → a payee-less Expense (only an Expense can claim the tax)
 *   inflow  + contact             → the plain journal entry, contact on the contra leg
 *   otherwise                     → the plain journal entry (unchanged behaviour)
 *
 * Every path posts through the audited posters, stamps the line as Created with
 * the bank-side journal line (what the reconciliation ticks) and the entry it
 * created, and — on the expense path — remembers the category and tax code as
 * the vendor's defaults when they had none. The statement amount is the GROSS:
 * an expense with a tax code is saved tax-inclusive (see SaveExpense's
 * amount_includes_tax), so the payment leg still equals the statement to the cent.
 */
final class RecordStatementLine
{
    public function __construct(
        private readonly AddStatementLineEntry $journalEntry,
        private readonly SaveExpense $saveExpense,
        private readonly ExpensePoster $expensePoster,
        private readonly SaveBillPayment $saveBillPayment,
        private readonly BillPaymentPoster $billPaymentPoster,
        private readonly OpenBillMatcher $bills,
    ) {}

    /**
     * @throws PostingValidationException for a caller-fixable problem (already added, missing category, stale bill…)
     */
    public function handle(
        BankStatementLine $line,
        ?int $accountId,
        ?int $contactId = null,
        ?int $billId = null,
        bool $rememberVendorDefault = true,
        ?int $taxCodeId = null,
        ?int $secondaryTaxCodeId = null,
        ?array $billAllocations = null,
    ): JournalEntry {
        return DB::transaction(function () use ($line, $accountId, $contactId, $billId, $rememberVendorDefault, $taxCodeId, $secondaryTaxCodeId, $billAllocations): JournalEntry {
            if ($line->created_journal_entry_id !== null) {
                throw new PostingValidationException(__('This transaction has already been added to your books.'));
            }

            /** @var Account $account */
            $account = $line->account()->firstOrFail();

            if ($accountId !== null && $accountId === (int) $account->id) {
                throw new PostingValidationException(__('Choose a category other than the bank account itself.'));
            }

            $contact = $contactId !== null ? $this->resolveContact($line, $contactId) : null;
            $allocations = $billAllocations !== null && $billAllocations !== [] ? array_values($billAllocations) : null;
            $paysBills = $billId !== null || $allocations !== null;

            if ($paysBills && $contact === null) {
                throw new PostingValidationException(__('Choose the vendor before paying one of their bills.'));
            }

            if ($paysBills && ! $line->isOutflow()) {
                throw new PostingValidationException(__('A bill can only be paid by money going out.'));
            }

            [$primaryTax, $secondaryTax] = $line->isOutflow()
                ? $this->resolveTaxCodes($line, $taxCodeId, $secondaryTaxCodeId)
                : [null, null];

            if ($line->isOutflow() && $contact !== null && $paysBills) {
                $entry = $this->recordBillPayment($line, $account, $contact, $allocations === null ? $billId : null, $allocations);
                [$primaryTax, $secondaryTax] = [null, null]; // a bill already carries its tax
            } elseif ($line->isOutflow() && ($contact !== null || $primaryTax !== null)) {
                $entry = $this->recordExpense($line, $account, $contact, $this->requireAccount($accountId), $primaryTax, $secondaryTax);

                if ($rememberVendorDefault && $contact !== null) {
                    $this->rememberVendorDefaults($contact, (int) $accountId, $primaryTax);
                }
            } else {
                $entry = $this->journalEntry->handle($line, $this->requireAccount($accountId), $contact?->id);
            }

            $bankLine = $entry->lines()
                ->where('account_id', $account->id)
                ->orderBy('line_order')
                ->firstOrFail();

            $line->forceFill([
                'suggested_account_id' => $accountId ?? $line->suggested_account_id,
                'suggested_contact_id' => $contact?->id,
                'suggested_bill_id' => $allocations === null ? $billId : null,
                'suggested_bill_allocations' => $allocations,
                'suggested_tax_code_id' => $primaryTax?->id,
                'suggested_secondary_tax_code_id' => $secondaryTax?->id,
                'suggestion_source' => $line->suggestion_source->value ?? StatementSuggestionSource::User->value,
                'created_journal_entry_id' => $entry->id,
                'matched_journal_line_id' => $bankLine->id,
                'match_status' => StatementLineMatchStatus::Created->value,
            ])->save();

            return $entry;
        });
    }

    private function recordExpense(
        BankStatementLine $line,
        Account $account,
        ?Contact $contact,
        int $accountId,
        ?TaxCode $primaryTax,
        ?TaxCode $secondaryTax,
    ): JournalEntry {
        $memo = $this->memo($line);

        $expense = $this->saveExpense->handle([
            'payment_account_id' => $account->id,
            'expense_date' => CarbonImmutable::parse($line->txn_date)->toDateString(),
            'payee_contact_id' => $contact?->id,
            'payee_name' => $contact->display_name ?? $memo,
            'reference' => $this->reference($line),
            'memo' => $memo,
            'lines' => [[
                'account_id' => $accountId,
                'description' => $memo,
                'amount_cents' => abs((int) $line->amount_cents), // the gross
                'tax_code_id' => $primaryTax?->id,
                'secondary_tax_code_id' => $secondaryTax?->id,
                'amount_includes_tax' => $primaryTax !== null,
            ]],
        ]);

        return $this->expensePoster->post($expense);
    }

    /**
     * Validate the chosen purchase tax code(s): active, purchase-eligible, this
     * company's, and two different codes. A lone secondary is promoted to primary.
     *
     * @return array{0: TaxCode|null, 1: TaxCode|null}
     */
    private function resolveTaxCodes(BankStatementLine $line, ?int $taxCodeId, ?int $secondaryTaxCodeId): array
    {
        if ($taxCodeId === null && $secondaryTaxCodeId !== null) {
            [$taxCodeId, $secondaryTaxCodeId] = [$secondaryTaxCodeId, null];
        }

        if ($taxCodeId === null) {
            return [null, null];
        }

        if ($secondaryTaxCodeId === $taxCodeId) {
            throw new PostingValidationException(__('Choose two different tax codes.'));
        }

        return [
            $this->resolveTaxCode($line, $taxCodeId),
            $secondaryTaxCodeId !== null ? $this->resolveTaxCode($line, $secondaryTaxCodeId) : null,
        ];
    }

    private function resolveTaxCode(BankStatementLine $line, int $taxCodeId): TaxCode
    {
        $code = TaxCode::query()
            ->where('company_id', $line->company_id)
            ->usableForPurchases()
            ->whereKey($taxCodeId)
            ->first();

        if ($code === null) {
            throw new PostingValidationException(__('That tax code is no longer available for purchases — choose another.'));
        }

        return $code;
    }

    /**
     * One payment across one bill (paid in full) or an exact-sum set of bills
     * (partials allowed). The payment type follows the bills — vendor bills or
     * an employee's reimbursements — never a hardcoded default.
     *
     * @param  list<array{bill_id: int, amount_cents: int}>|null  $allocations
     */
    private function recordBillPayment(BankStatementLine $line, Account $account, Contact $contact, ?int $billId, ?array $allocations): JournalEntry
    {
        $amount = abs((int) $line->amount_cents);

        if ($allocations !== null) {
            $bills = $this->bills->assertPayableSet($line, $contact, $allocations);
            $applications = array_map(fn (array $a): array => [
                'bill_id' => (int) $a['bill_id'],
                'amount_cents' => (int) $a['amount_cents'],
            ], $allocations);
        } else {
            $bill = Bill::query()
                ->where('company_id', $line->company_id)
                ->whereKey((int) $billId)
                ->first();

            if ($bill === null) {
                throw new PostingValidationException(__('That bill no longer exists — record the line as an expense instead.'));
            }

            $this->bills->assertPayable($line, $contact, $bill);

            $bills = collect([$bill->id => $bill]);
            $applications = [['bill_id' => $bill->id, 'amount_cents' => $amount]];
        }

        $type = $bills->first()?->getAttribute('bill_type');
        $paymentType = $type instanceof BillType ? $type : BillType::Vendor;

        $payment = $this->saveBillPayment->handle([
            'contact_id' => $contact->id,
            'payment_type' => $paymentType->value,
            'payment_date' => CarbonImmutable::parse($line->txn_date)->toDateString(),
            'paid_from_account_id' => $account->id,
            'reference' => $this->reference($line),
            'amount_cents' => $amount,
            'memo' => $this->memo($line),
            'applications' => $applications,
        ]);

        return $this->billPaymentPoster->post($payment);
    }

    private function resolveContact(BankStatementLine $line, int $contactId): Contact
    {
        $contact = Contact::query()
            ->where('company_id', $line->company_id)
            ->whereKey($contactId)
            ->first();

        if ($contact === null) {
            throw new PostingValidationException(__('That contact no longer exists — choose another.'));
        }

        return $contact;
    }

    private function requireAccount(?int $accountId): int
    {
        if ($accountId === null) {
            throw new PostingValidationException(__('Choose a category for this transaction.'));
        }

        return $accountId;
    }

    /**
     * A vendor with no default expense account (or tax code) learns the ones
     * just used, so the next line for them pre-fills them (see
     * CategorySuggester::fromContact()). Existing defaults are never overwritten.
     */
    private function rememberVendorDefaults(Contact $contact, int $accountId, ?TaxCode $primaryTax): void
    {
        $changes = [];

        if ($contact->default_expense_account_id === null) {
            $usable = Account::query()
                ->where('company_id', $contact->company_id)
                ->whereKey($accountId)
                ->selectableForItemAccount()
                ->where('is_active', true)
                ->exists();

            if ($usable) {
                $changes['default_expense_account_id'] = $accountId;
            }
        }

        if ($primaryTax !== null && $contact->default_tax_code_id === null) {
            $changes['default_tax_code_id'] = $primaryTax->id;
        }

        if ($changes !== []) {
            $contact->forceFill($changes)->saveQuietly();
        }
    }

    private function memo(BankStatementLine $line): string
    {
        $description = trim((string) $line->description);

        return $description !== '' ? $description : __('Bank statement import');
    }

    private function reference(BankStatementLine $line): ?string
    {
        $reference = trim((string) ($line->reference ?: $line->check_number));

        return $reference !== '' ? mb_substr($reference, 0, 100) : null;
    }
}
