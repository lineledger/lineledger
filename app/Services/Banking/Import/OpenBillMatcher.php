<?php

namespace App\Services\Banking\Import;

use App\Enums\BillType;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Support\Collection;

/**
 * Finds the open bill(s) an imported outflow appears to settle: posted or
 * partial bills of the kinds the payee can be paid for — vendor bills for a
 * vendor, expense-claim reimbursements for an employee, both for a contact
 * who is both — whose remaining balance equals the line amount, in a currency
 * the bank account can pay. When no single bill matches, an exact-sum set of
 * the payee's open bills is offered ({@see allocationFor()}).
 *
 * Suggest-only: recording the payment happens in RecordStatementLine, which
 * re-validates through {@see assertPayable()} / {@see assertPayableSet()}
 * because a bill may have been paid elsewhere since the offer was made.
 */
final class OpenBillMatcher
{
    /** Subset search is only attempted when the payee has this many open bills or fewer. */
    public const MAX_SUBSET_BILLS = 10;

    /**
     * Candidate bills for one line, optionally restricted to a payee.
     *
     * @return Collection<int, Bill>
     */
    public function candidates(BankStatementLine $line, ?int $contactId = null): Collection
    {
        $overrides = $contactId !== null ? [$line->id => $contactId] : [];

        return $this->forLines(collect([$line]), $overrides, allowCompanyWide: $contactId === null)[$line->id] ?? collect();
    }

    /**
     * Exactly one candidate → that bill; zero or several → null.
     */
    public function forLine(BankStatementLine $line, ?int $contactId = null): ?Bill
    {
        $candidates = $this->candidates($line, $contactId);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Candidates for many lines in one query. A line's payee is taken from
     * $contactIdByLine when given, else its suggested_contact_id; lines with no
     * payee are matched company-wide only when $allowCompanyWide is set.
     *
     * @param  Collection<int, BankStatementLine>  $lines
     * @param  array<int, int|null>  $contactIdByLine
     * @return array<int, Collection<int, Bill>> keyed by line id; only lines with at least one candidate
     */
    public function forLines(Collection $lines, array $contactIdByLine = [], bool $allowCompanyWide = false): array
    {
        $outflows = $lines->filter(fn (BankStatementLine $line): bool => $line->isOutflow())->values();

        if ($outflows->isEmpty()) {
            return [];
        }

        $companyId = (int) $outflows->first()->company_id;
        $payeeFor = fn (BankStatementLine $line): ?int => array_key_exists($line->id, $contactIdByLine)
            ? ($contactIdByLine[$line->id] !== null ? (int) $contactIdByLine[$line->id] : null)
            : ($line->suggested_contact_id !== null ? (int) $line->suggested_contact_id : null);

        $contactIds = $outflows->map($payeeFor)->filter()->unique()->values();

        if ($contactIds->isEmpty() && ! $allowCompanyWide) {
            return [];
        }

        $bills = Bill::query()
            ->where('company_id', $companyId)
            ->openWithBalance()
            ->when(! $allowCompanyWide, fn ($q) => $q->whereIn('contact_id', $contactIds))
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        if ($bills->isEmpty()) {
            return [];
        }

        $company = Company::query()->findOrFail($companyId);
        $accounts = Account::withoutGlobalScopes()
            ->whereIn('id', $outflows->pluck('account_id')->unique()->all())
            ->get()
            ->keyBy('id');
        $contacts = $contactIds->isEmpty() ? collect() : Contact::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $contactIds)
            ->get(['id', 'is_vendor', 'is_employee'])
            ->keyBy('id');

        $result = [];

        foreach ($outflows as $line) {
            $contactId = $payeeFor($line);

            if ($contactId === null && ! $allowCompanyWide) {
                continue;
            }

            /** @var Account|null $account */
            $account = $accounts->get($line->account_id);

            if ($account === null) {
                continue;
            }

            $amount = abs((int) $line->amount_cents);
            $types = $this->billTypesFor($contactId !== null ? $contacts->get($contactId) : null);

            $matches = $bills
                ->filter(fn (Bill $bill): bool => ($contactId === null || (int) $bill->contact_id === $contactId)
                    && $this->hasType($bill, $types)
                    && $bill->balanceCents() === $amount
                    && $this->currencyCompatible($company, $account, $bill))
                ->values();

            if ($matches->isNotEmpty()) {
                $result[$line->id] = $matches;
            }
        }

        return $result;
    }

    /**
     * Every open, currency-compatible bill this payee could settle from the
     * line (vendor bills and/or reimbursements per their roles), oldest due first.
     *
     * @return Collection<int, Bill>
     */
    public function openBillsFor(BankStatementLine $line, Contact $contact): Collection
    {
        /** @var Account $account */
        $account = $line->account()->firstOrFail();
        $company = $account->company;
        $types = $this->billTypesFor($contact);

        return Bill::query()
            ->where('company_id', $line->company_id)
            ->where('contact_id', $contact->id)
            ->whereIn('bill_type', array_map(fn (BillType $t): string => $t->value, $types))
            ->openWithBalance()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Bill $bill): bool => $this->currencyCompatible($company, $account, $bill))
            ->values();
    }

    /**
     * An exact-sum set of the payee's open bills for this outflow, paid in full:
     * the oldest-due bills that fit first, else the single subset that adds up.
     * Null when no single-bill match is needed instead, when the sum is
     * ambiguous, or when the payee has more open bills than we will search.
     *
     * @return list<array{bill_id: int, amount_cents: int}>|null
     */
    public function allocationFor(BankStatementLine $line, int $contactId): ?array
    {
        if (! $line->isOutflow()) {
            return null;
        }

        $contact = Contact::withoutGlobalScopes()
            ->where('company_id', $line->company_id)
            ->find($contactId);

        if ($contact === null) {
            return null;
        }

        $bills = $this->openBillsFor($line, $contact);
        $count = $bills->count();

        if ($count < 2 || $count > self::MAX_SUBSET_BILLS) {
            return null;
        }

        $target = abs((int) $line->amount_cents);
        $balances = $bills->map(fn (Bill $bill): int => $bill->balanceCents())->all();
        $ids = $bills->map(fn (Bill $bill): int => (int) $bill->id)->all();

        // Oldest due first: take every bill that still fits.
        $sum = 0;
        $picked = [];
        foreach ($balances as $i => $balance) {
            if ($sum + $balance <= $target) {
                $sum += $balance;
                $picked[] = $i;
            }
        }

        if ($sum !== $target) {
            // Otherwise the unique subset that adds up exactly, if there is one.
            $picked = null;
            for ($mask = 1; $mask < (1 << $count); $mask++) {
                $sum = 0;
                for ($i = 0; $i < $count; $i++) {
                    if ($mask & (1 << $i)) {
                        $sum += $balances[$i];
                    }
                }

                if ($sum !== $target) {
                    continue;
                }

                if ($picked !== null) {
                    return null; // ambiguous
                }

                $picked = [];
                for ($i = 0; $i < $count; $i++) {
                    if ($mask & (1 << $i)) {
                        $picked[] = $i;
                    }
                }
            }
        }

        if ($picked === null || count($picked) < 2) {
            return null;
        }

        return array_map(fn (int $i): array => ['bill_id' => $ids[$i], 'amount_cents' => $balances[$i]], $picked);
    }

    /**
     * Re-check, at post time, that this line can settle this one bill in full.
     *
     * @throws PostingValidationException
     */
    public function assertPayable(BankStatementLine $line, Contact $contact, Bill $bill): void
    {
        if (! $line->isOutflow()) {
            throw new PostingValidationException(__('A bill can only be paid by money going out.'));
        }

        /** @var Account $account */
        $account = $line->account()->firstOrFail();
        $company = $account->company;

        $this->assertSettleable($line, $contact, $company, $account, $bill, $this->billTypesFor($contact));

        if ($bill->balanceCents() !== abs((int) $line->amount_cents)) {
            throw new PostingValidationException(__('That bill\'s remaining balance no longer matches this amount.'));
        }

        if ($bill->getAttribute('bill_type') === BillType::Reimbursement) {
            $this->assertReimbursementPayable($contact, $company, $account);
        }
    }

    /**
     * Re-check, at post time and under row locks, that this line can settle
     * this set of bills with these amounts. Returns the bills keyed by id.
     *
     * @param  list<array{bill_id: int, amount_cents: int}>  $allocations
     * @return Collection<int, Bill>
     *
     * @throws PostingValidationException
     */
    public function assertPayableSet(BankStatementLine $line, Contact $contact, array $allocations): Collection
    {
        if (! $line->isOutflow()) {
            throw new PostingValidationException(__('A bill can only be paid by money going out.'));
        }

        if ($allocations === []) {
            throw new PostingValidationException(__('Choose at least one bill to pay.'));
        }

        $amountByBill = [];

        foreach ($allocations as $allocation) {
            $billId = (int) $allocation['bill_id'];
            $amount = (int) $allocation['amount_cents'];

            if ($billId <= 0 || $amount <= 0) {
                throw new PostingValidationException(__('Apply a positive amount to each bill.'));
            }

            if (isset($amountByBill[$billId])) {
                throw new PostingValidationException(__('Each bill can only be paid once in the same payment.'));
            }

            $amountByBill[$billId] = $amount;
        }

        $bills = Bill::query()
            ->where('company_id', $line->company_id)
            ->whereIn('id', array_keys($amountByBill))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var Account $account */
        $account = $line->account()->firstOrFail();
        $company = $account->company;
        $types = $this->billTypesFor($contact);
        $setType = null;

        foreach ($amountByBill as $billId => $amount) {
            /** @var Bill|null $bill */
            $bill = $bills->get($billId);

            if ($bill === null) {
                throw new PostingValidationException(__('Bill #:id no longer exists — reopen Pay bills on this line.', ['id' => $billId]));
            }

            $this->assertSettleable($line, $contact, $company, $account, $bill, $types);

            $billType = $bill->getAttribute('bill_type');

            if ($setType !== null && $billType !== $setType) {
                throw new PostingValidationException(__('Bills and reimbursements cannot be paid together — record them as separate lines.'));
            }

            $setType = $billType;

            if ($amount > $bill->balanceCents()) {
                throw new PostingValidationException(__("Bill :no's remaining balance changed since it was offered — reopen Pay bills on this line.", [
                    'no' => $bill->bill_no ?: '#'.$bill->id,
                ]));
            }
        }

        if (array_sum($amountByBill) !== abs((int) $line->amount_cents)) {
            throw new PostingValidationException(__('The amounts applied must add up to the transaction total.'));
        }

        if ($setType === BillType::Reimbursement) {
            $this->assertReimbursementPayable($contact, $company, $account);
        }

        return $bills;
    }

    /**
     * @param  list<BillType>  $types
     */
    private function assertSettleable(BankStatementLine $line, Contact $contact, Company $company, Account $account, Bill $bill, array $types): void
    {
        if ((int) $bill->company_id !== (int) $line->company_id || (int) $bill->contact_id !== (int) $contact->id) {
            throw new PostingValidationException(__('That bill belongs to a different vendor.'));
        }

        if (! $this->hasType($bill, $types)) {
            throw new PostingValidationException(__("That bill's type does not match this contact — check the contact's roles."));
        }

        if (! $bill->isOpen()) {
            throw new PostingValidationException(__('That bill is no longer open.'));
        }

        if (! $this->currencyCompatible($company, $account, $bill)) {
            throw new PostingValidationException(__('The bill and the bank account use different currencies — pay it from the Bills screen.'));
        }
    }

    private function assertReimbursementPayable(Contact $contact, Company $company, Account $account): void
    {
        if (! $contact->is_employee) {
            throw new PostingValidationException(__("Only an employee can be paid a reimbursement — check the contact's roles."));
        }

        if ($account->currency_code !== null && ! $company->isHomeCurrency($account->currency_code)) {
            throw new PostingValidationException(__('Reimbursements are paid in your home currency — choose a home-currency bank account or pay it from the Reimbursements screen.'));
        }

        $exists = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->employeeReimbursementsPayable()
            ->exists();

        if (! $exists) {
            throw new PostingValidationException(__('This organization has no Employee Reimbursements Payable account. Turn on Employees under Settings, then pay this reimbursement from the Reimbursements screen.'));
        }
    }

    /**
     * The kinds of bill a payee can be paid for. No payee (company-wide search)
     * considers both; a contact with neither role is treated as a vendor.
     *
     * @return list<BillType>
     */
    private function billTypesFor(?Contact $contact): array
    {
        if ($contact === null) {
            return [BillType::Vendor, BillType::Reimbursement];
        }

        $types = [];

        if ($contact->is_vendor) {
            $types[] = BillType::Vendor;
        }

        if ($contact->is_employee) {
            $types[] = BillType::Reimbursement;
        }

        return $types === [] ? [BillType::Vendor] : $types;
    }

    /**
     * @param  list<BillType>  $types
     */
    private function hasType(Bill $bill, array $types): bool
    {
        $type = $bill->getAttribute('bill_type');

        return $type instanceof BillType && in_array($type, $types, true);
    }

    private function currencyCompatible(Company $company, Account $account, Bill $bill): bool
    {
        $home = mb_strtoupper((string) $company->currency_code);
        $accountCode = mb_strtoupper((string) ($account->currency_code ?: $home));
        $billCode = mb_strtoupper((string) ($bill->getAttribute('currency_code') ?: $home));

        return $accountCode === $billCode;
    }
}
