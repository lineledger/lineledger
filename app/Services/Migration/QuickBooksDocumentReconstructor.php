<?php

namespace App\Services\Migration;

use App\Enums\AccountSubtype;
use App\Enums\BillPaymentStatus;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\ChequeStatus;
use App\Enums\CreditMemoStatus;
use App\Enums\DepositStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * Reconstructs a native source document (Invoice, Credit Memo, Customer Receipt,
 * Bill, Bill Payment, Deposit, Cheque) from a replayed QuickBooks transaction.
 *
 * The exact QuickBooks journal entry is posted by the caller and passed in; this
 * service only builds the document "shell" with account-level lines and links it
 * to that entry (document.journal_entry_id ↔ entry.source_type/source_id). It
 * never re-derives the GL, so financial statements are unaffected. Documents whose
 * type isn't recognised — or which lack a required contact — return null and remain
 * a plain journal entry.
 */
class QuickBooksDocumentReconstructor
{
    /** @var array<string, int> "{role}|{lowercased name}" => contact id (per-import cache) */
    protected array $contactCache = [];

    /** @var array<string, array<string, bool>> document numbers used per type, to keep them unique */
    protected array $usedNumbers = [];

    /**
     * Build the document for a posted entry. Returns the created model, or null to
     * leave the transaction as a plain journal entry.
     *
     * @param  array<string, mixed>  $block
     * @param  list<array{account: Account, line: array<string, mixed>}>  $resolved
     */
    public function build(JournalEntry $entry, array $block, array $resolved, ?int $contactId): ?Model
    {
        $category = $this->category((string) ($block['type'] ?? ''));

        if ($category === null) {
            return null;
        }

        $lines = $this->classify($resolved);

        $document = match ($category) {
            'invoice' => $this->buildInvoice($entry, $block, $lines, $contactId),
            'credit_memo' => $this->buildCreditMemo($entry, $block, $lines, $contactId),
            'receipt' => $this->buildReceipt($entry, $block, $lines, $contactId),
            'bill' => $this->buildBill($entry, $block, $lines, $contactId),
            'bill_payment' => $this->buildBillPayment($entry, $block, $lines, $contactId),
            'deposit' => $this->buildDeposit($entry, $block, $lines),
            'cheque' => $this->buildCheque($entry, $block, $lines, $contactId),
            default => null,
        };

        if ($document !== null) {
            // Point the journal entry at the document it now belongs to, and the
            // document back at its entry, so each is reachable from the other.
            $entry->forceFill([
                'source_type' => $document->getMorphClass(),
                'source_id' => $document->getKey(),
            ])->save();

            $document->forceFill(['journal_entry_id' => $entry->id])->save();
        }

        return $document;
    }

    /**
     * Map a QuickBooks transaction type to a document category we can reconstruct.
     */
    protected function category(string $type): ?string
    {
        $t = strtolower(trim($type));

        return match (true) {
            $t === 'invoice' => 'invoice',
            str_contains($t, 'credit memo') => 'credit_memo',
            str_starts_with($t, 'bill pmt') => 'bill_payment',
            $t === 'bill' => 'bill',
            $t === 'payment' || $t === 'receive payment' => 'receipt',
            $t === 'cheque' || $t === 'check' => 'cheque',
            $t === 'deposit' => 'deposit',
            default => null, // Sales Receipt, General Journal, Transfer, etc. → plain JE
        };
    }

    /**
     * Resolve the party for a document from the transaction's name. The journal carries
     * the customer/vendor on every line (e.g. "Ziyone, Joan - 26/310"), so when it
     * doesn't match an already-imported contact we find-or-create one from that name —
     * rather than dropping back to a plain journal entry. Cached per import.
     */
    protected function resolveContact(int $companyId, ?string $name, string $role, bool $create = true): ?int
    {
        $name = $name !== null ? trim($name) : '';

        if ($name === '') {
            return null;
        }

        $key = ($create ? $role : 'match').'|'.mb_strtolower($name);

        if (array_key_exists($key, $this->contactCache)) {
            return $this->contactCache[$key];
        }

        $query = Contact::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('display_name', $name);

        // Match-only (cheques): we can't tell a vendor from an employee, so never create.
        // Prefer an existing customer, then an employee, then any other contact.
        if (! $create) {
            $contact = $query->orderByRaw('is_customer desc, is_employee desc')->first();

            return $this->contactCache[$key] = $contact?->id;
        }

        $contact = $query->first();

        if ($contact === null) {
            $contact = Contact::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'display_name' => $name,
                'is_customer' => $role === 'customer',
                'is_vendor' => $role === 'vendor',
                'is_active' => true,
            ]);
        } elseif ($role === 'customer' && ! $contact->is_customer) {
            $contact->forceFill(['is_customer' => true])->save();
        } elseif ($role === 'vendor' && ! $contact->is_vendor) {
            $contact->forceFill(['is_vendor' => true])->save();
        }

        return $this->contactCache[$key] = (int) $contact->id;
    }

    /**
     * Split resolved lines into control/body buckets by account subtype.
     *
     * @param  list<array{account: Account, line: array<string, mixed>}>  $resolved
     * @return array{ar: list, ap: list, bank: list, tax: list, other: list}
     */
    protected function classify(array $resolved): array
    {
        $buckets = ['ar' => [], 'ap' => [], 'bank' => [], 'undeposited' => [], 'tax' => [], 'other' => []];

        foreach ($resolved as $item) {
            $subtype = $item['account']->subtype;
            $key = match ($subtype) {
                AccountSubtype::AccountsReceivable => 'ar',
                AccountSubtype::AccountsPayable => 'ap',
                AccountSubtype::Bank => 'bank',
                AccountSubtype::UndepositedFunds => 'undeposited',
                AccountSubtype::TaxPayable => 'tax',
                default => 'other',
            };
            $buckets[$key][] = $item;
        }

        return $buckets;
    }

    /**
     * First line with a debit balance, or null. Used to locate a payment's deposit-to
     * account when its subtype doesn't identify it.
     *
     * @param  list<array{account: Account, line: array<string, mixed>}>  $items
     * @return array{account: Account, line: array<string, mixed>}|null
     */
    protected function firstDebit(array $items): ?array
    {
        foreach ($items as $item) {
            if ((int) $item['line']['debit_cents'] > 0) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  list<array{account: Account, line: array<string, mixed>}>  $items
     */
    protected function sumDebit(array $items): int
    {
        return array_sum(array_map(fn ($i) => (int) $i['line']['debit_cents'], $items));
    }

    /**
     * @param  list<array{account: Account, line: array<string, mixed>}>  $items
     */
    protected function sumCredit(array $items): int
    {
        return array_sum(array_map(fn ($i) => (int) $i['line']['credit_cents'], $items));
    }

    protected function buildInvoice(JournalEntry $entry, array $block, array $lines, ?int $contactId): ?Invoice
    {
        $total = $this->sumDebit($lines['ar']);
        $contactId ??= $this->resolveContact($entry->company_id, $block['name'] ?? null, 'customer');

        if ($lines['ar'] === [] || $contactId === null || $total <= 0) {
            return null;
        }

        $subtotal = $this->sumCredit($lines['other']);
        $tax = $this->sumCredit($lines['tax']);

        $invoice = Invoice::withoutGlobalScopes()->create([
            'company_id' => $entry->company_id,
            'contact_id' => $contactId,
            'invoice_no' => $this->docNo($block, $entry, 'INV'),
            'invoice_date' => $entry->entry_date,
            'due_date' => $entry->entry_date,
            'status' => InvoiceStatus::Posted,
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'total_cents' => $total,
            'amount_paid_cents' => 0,
            'memo' => $block['memo'] ?? null,
            'is_opening_balance' => false,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
        ]);

        $this->writeLines($invoice->lines(), $lines['other'], 'credit_cents');

        return $invoice;
    }

    protected function buildCreditMemo(JournalEntry $entry, array $block, array $lines, ?int $contactId): ?CreditMemo
    {
        $total = $this->sumCredit($lines['ar']);
        $contactId ??= $this->resolveContact($entry->company_id, $block['name'] ?? null, 'customer');

        if ($lines['ar'] === [] || $contactId === null || $total <= 0) {
            return null;
        }

        $memo = CreditMemo::withoutGlobalScopes()->create([
            'company_id' => $entry->company_id,
            'contact_id' => $contactId,
            'credit_memo_no' => $this->docNo($block, $entry, 'CM'),
            'credit_memo_date' => $entry->entry_date,
            'status' => CreditMemoStatus::Posted,
            'subtotal_cents' => $this->sumDebit($lines['other']),
            'tax_cents' => $this->sumDebit($lines['tax']),
            'total_cents' => $total,
            'memo' => $block['memo'] ?? null,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
        ]);

        $this->writeLines($memo->lines(), $lines['other'], 'debit_cents');

        return $memo;
    }

    protected function buildReceipt(JournalEntry $entry, array $block, array $lines, ?int $contactId): ?CustomerReceipt
    {
        $amount = $this->sumCredit($lines['ar']);
        $contactId ??= $this->resolveContact($entry->company_id, $block['name'] ?? null, 'customer');
        // A receipt is deposited to a bank account or Undeposited Funds. QuickBooks
        // types its Undeposited Funds as "Other Current Asset", so fall back to the
        // payment's debit line when neither subtype is present.
        $depositTo = $lines['bank'][0] ?? $lines['undeposited'][0] ?? $this->firstDebit($lines['other']);

        if ($lines['ar'] === [] || $depositTo === null || $contactId === null || $amount <= 0) {
            return null;
        }

        return CustomerReceipt::withoutGlobalScopes()->create([
            'company_id' => $entry->company_id,
            'contact_id' => $contactId,
            'receipt_no' => $this->docNo($block, $entry, 'RCPT'),
            'receipt_date' => $entry->entry_date,
            'deposit_to_account_id' => $depositTo['account']->id,
            'reference' => $block['num'] ?? null,
            'amount_cents' => $amount,
            'memo' => $block['memo'] ?? null,
            'status' => ReceiptStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
        ]);
    }

    protected function buildBill(JournalEntry $entry, array $block, array $lines, ?int $contactId): ?Bill
    {
        $total = $this->sumCredit($lines['ap']);
        $contactId ??= $this->resolveContact($entry->company_id, $block['name'] ?? null, 'vendor');

        if ($lines['ap'] === [] || $contactId === null || $total <= 0) {
            return null;
        }

        $bill = Bill::withoutGlobalScopes()->create([
            'company_id' => $entry->company_id,
            'contact_id' => $contactId,
            'bill_type' => BillType::Vendor,
            'bill_no' => $this->docNo($block, $entry, 'BILL'),
            'vendor_reference' => $block['num'] ?? null,
            'bill_date' => $entry->entry_date,
            'due_date' => $entry->entry_date,
            'status' => BillStatus::Posted,
            'subtotal_cents' => $this->sumDebit($lines['other']),
            'tax_cents' => $this->sumDebit($lines['tax']),
            'total_cents' => $total,
            'amount_paid_cents' => 0,
            'memo' => $block['memo'] ?? null,
            'is_opening_balance' => false,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
        ]);

        $this->writeLines($bill->lines(), $lines['other'], 'debit_cents');

        return $bill;
    }

    protected function buildBillPayment(JournalEntry $entry, array $block, array $lines, ?int $contactId): ?BillPayment
    {
        $amount = $this->sumDebit($lines['ap']);
        $contactId ??= $this->resolveContact($entry->company_id, $block['name'] ?? null, 'vendor');

        if ($lines['ap'] === [] || $lines['bank'] === [] || $contactId === null || $amount <= 0) {
            return null;
        }

        return BillPayment::withoutGlobalScopes()->create([
            'company_id' => $entry->company_id,
            'contact_id' => $contactId,
            'payment_type' => BillType::Vendor,
            'payment_no' => $this->docNo($block, $entry, 'BP'),
            'payment_date' => $entry->entry_date,
            'paid_from_account_id' => $lines['bank'][0]['account']->id,
            'reference' => $block['num'] ?? null,
            'amount_cents' => $amount,
            'memo' => $block['memo'] ?? null,
            'status' => BillPaymentStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
        ]);
    }

    protected function buildDeposit(JournalEntry $entry, array $block, array $lines): ?Deposit
    {
        // The receiving bank is the debit; the body is everything else (typically
        // Undeposited Funds lines, each naming the customer being deposited).
        $amount = $this->sumDebit($lines['bank']);
        $bodyLines = array_merge($lines['undeposited'], $lines['other'], $lines['tax'], $lines['ar'], $lines['ap']);

        if ($lines['bank'] === [] || $amount <= 0 || $bodyLines === []) {
            return null;
        }

        $deposit = Deposit::withoutGlobalScopes()->create([
            'company_id' => $entry->company_id,
            'bank_account_id' => $lines['bank'][0]['account']->id,
            'deposit_no' => $this->docNo($block, $entry, 'DEP'),
            'deposit_date' => $entry->entry_date,
            'amount_cents' => $amount,
            'memo' => $block['memo'] ?? null,
            'status' => DepositStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
        ]);

        $order = 0;
        foreach ($bodyLines as $item) {
            // Each undeposited-funds line names the customer being deposited.
            $deposit->lines()->create([
                'account_id' => $item['account']->id,
                'contact_id' => $this->resolveContact($entry->company_id, $item['line']['name'] ?? null, 'customer'),
                'description' => $item['line']['memo'] ?? $item['line']['name'] ?? null,
                'amount_cents' => (int) $item['line']['credit_cents'],
                'line_order' => $order++,
            ]);
        }

        return $deposit;
    }

    protected function buildCheque(JournalEntry $entry, array $block, array $lines, ?int $contactId): ?Cheque
    {
        $amount = $this->sumCredit($lines['bank']);
        $bodyLines = array_merge($lines['other'], $lines['tax']);

        if ($lines['bank'] === [] || $amount <= 0 || $bodyLines === []) {
            return null;
        }

        // A cheque payee may be a vendor or an employee — match an existing contact
        // (customer first, then employee) but never auto-create; otherwise just keep
        // the free-text payee name.
        $payeeContactId = $this->resolveContact($entry->company_id, $block['name'] ?? null, 'customer', create: false) ?? $contactId;

        $cheque = Cheque::withoutGlobalScopes()->create([
            'company_id' => $entry->company_id,
            'bank_account_id' => $lines['bank'][0]['account']->id,
            'cheque_no' => $this->docNo($block, $entry, 'CHQ'),
            'cheque_date' => $entry->entry_date,
            'payee_contact_id' => $payeeContactId,
            'payee_name' => $block['name'] ?? 'Imported',
            'amount_cents' => $amount,
            'memo' => $block['memo'] ?? null,
            'status' => ChequeStatus::Posted,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
        ]);

        $order = 0;
        foreach ($bodyLines as $item) {
            $cheque->lines()->create([
                'account_id' => $item['account']->id,
                'description' => $item['line']['memo'] ?? $item['line']['name'] ?? null,
                'amount_cents' => (int) $item['line']['debit_cents'],
                'tax_cents' => 0,
                'line_order' => $order++,
            ]);
        }

        return $cheque;
    }

    /**
     * Write account-level document lines from the given resolved lines, taking each
     * line's amount from the named side (debit_cents or credit_cents).
     *
     * @param  list<array{account: Account, line: array<string, mixed>}>  $items
     */
    protected function writeLines(HasMany $relation, array $items, string $side): void
    {
        $order = 0;
        foreach ($items as $item) {
            $amount = (int) $item['line'][$side];

            $relation->create([
                'account_id' => $item['account']->id,
                'description' => $item['line']['memo'] ?? $item['line']['name'] ?? null,
                'quantity' => '1.0000',
                'unit_price_cents' => $amount,
                'line_subtotal_cents' => $amount,
                'line_tax_cents' => 0,
                'line_total_cents' => $amount,
                'line_order' => $order++,
            ]);
        }
    }

    /**
     * Use the QuickBooks document number when present, else a deterministic number
     * derived from the entry's idempotency hash (unique per transaction).
     *
     * @param  array<string, mixed>  $block
     */
    protected function docNo(array $block, JournalEntry $entry, string $prefix): string
    {
        $num = isset($block['num']) ? trim((string) $block['num']) : '';

        if ($num === '') {
            $num = $prefix.'-'.strtoupper(substr((string) $entry->source_external_id, 0, 10));
        }

        // QuickBooks reuses document numbers (e.g. "32/96" across many invoices), but
        // LineLedger requires them unique per company — suffix repeats within the import.
        $base = $num;
        $n = 1;
        while (isset($this->usedNumbers[$prefix][mb_strtolower($num)])) {
            $num = $base.'-'.(++$n);
        }
        $this->usedNumbers[$prefix][mb_strtolower($num)] = true;

        return $num;
    }
}
