<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Audit\PostedMutationGate;
use App\Services\Currency\ExchangeRateService;
use App\Support\Banking\BankLineMemo;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a customer receipt to the GL.
 *   DR  Deposit-to account (Undeposited Funds or Bank)   amount
 *   CR    Accounts Receivable                            amount
 * Applications update invoice.amount_paid_cents and status.
 */
class ReceiptPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected ControlAccountResolver $controlAccounts,
        protected ExchangeRateService $exchangeRates,
    ) {}

    public function post(CustomerReceipt $receipt): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($receipt) {
            $receipt->loadMissing('applications.invoice', 'company');

            if ($receipt->journal_entry_id) {
                throw AlreadyPostedException::for((int) $receipt->journal_entry_id);
            }

            if ($receipt->company->isLockedFor(CarbonImmutable::parse($receipt->receipt_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($receipt->receipt_date),
                    CarbonImmutable::parse($receipt->company->lock_date),
                );
            }

            $this->assertAmountValid($receipt);

            $ar = $this->controlAccounts->resolve($receipt->company, AccountSubtype::AccountsReceivable, $receipt->currency_code);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($receipt->company),
                'entry_date' => $receipt->receipt_date,
                'memo' => 'Receipt '.$receipt->receipt_no.' — '.$receipt->contact->display_name,
                'source_type' => CustomerReceipt::class,
                'source_id' => $receipt->id,
            ]);

            $this->buildReceiptLines($entry, $receipt, $ar);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $receipt->forceFill([
                'status' => ReceiptStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->applyToInvoices($receipt);

            $receipt->contact->recomputeArBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $receipt->company_id,
                AuditAction::CustomerReceiptPosted,
                $receipt,
                [
                    'receipt_no' => $receipt->receipt_no,
                    'receipt_date' => optional($receipt->receipt_date)->toDateString(),
                    'amount_cents' => (int) $receipt->amount_cents,
                    'contact_id' => (int) $receipt->contact_id,
                    'deposit_to_account_id' => (int) $receipt->deposit_to_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Re-post a posted receipt in place after the user edits it.
     *
     * Steps in a single transaction:
     *   1. Mutate the existing journal entry — delete its lines, rebuild from the
     *      edited deposit account/amount.
     *   2. Recompute every invoice the receipt applies to NOW and every invoice
     *      it applied to BEFORE the edit (SaveReceipt remembers those on the
     *      instance it returns) from the canonical ledger of live applications,
     *      so a receipt moved from one invoice to another leaves the old invoice
     *      unpaid again instead of stuck at "paid".
     *   3. Safety net: recompute any other invoice of the contact(s) involved
     *      whose cached paid amount disagrees with its live applications, so
     *      historic drift heals the next time the receipt is touched.
     *   4. Recompute affected account balances and the contact's AR balance.
     *
     * Lock-date is enforced on both the original posting date and the (possibly
     * new) receipt date — neither side can fall in a closed period.
     */
    public function repost(CustomerReceipt $receipt): JournalEntry
    {
        return PostedMutationGate::within(fn () => DB::transaction(fn () => AuditMute::silence(function () use ($receipt) {
            $receipt->loadMissing('applications.invoice', 'company', 'journalEntry.lines', 'contact');

            if (! $receipt->journal_entry_id) {
                throw new RuntimeException('Receipt has not been posted yet — call post() instead.');
            }

            if ($receipt->status === ReceiptStatus::Void) {
                throw new RuntimeException('Cannot repost a voided receipt.');
            }

            $entry = $receipt->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $receipt->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($receipt->receipt_date);

            if ($receipt->company->isLockedFor($originalEntryDate)) {
                throw PeriodLockedException::for($originalEntryDate, CarbonImmutable::parse($lockDate));
            }

            if ($receipt->company->isLockedFor($newEntryDate)) {
                throw PeriodLockedException::for($newEntryDate, CarbonImmutable::parse($lockDate));
            }

            $this->assertAmountValid($receipt);

            // Every invoice whose paid amount this edit can change: the ones
            // the receipt applies to now, plus the ones it applied to before
            // SaveReceipt rewrote the rows (it remembers them on the instance).
            // Each is recomputed from scratch below, which unwinds the old
            // application and applies the new one in one step.
            $touchedInvoiceIds = array_values(array_unique(array_map('intval', array_merge(
                $receipt->applications->pluck('invoice_id')->all(),
                $receipt->previousApplicationInvoiceIds(),
            ))));

            // Capture old JE account ids for balance recompute
            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            // 2. Mutate the journal entry
            $ar = $this->controlAccounts->resolve($receipt->company, AccountSubtype::AccountsReceivable, $receipt->currency_code);

            $entry->forceFill([
                'entry_date' => $receipt->receipt_date,
                'memo' => 'Receipt '.$receipt->receipt_no.' — '.$receipt->contact->display_name,
            ])->save();

            $entry->lines()->delete();

            $this->buildReceiptLines($entry, $receipt, $ar);

            $entry->refresh();

            if (! $entry->isBalanced()) {
                throw UnbalancedJournalException::from(
                    $entry->totalDebitsCents(),
                    $entry->totalCreditsCents(),
                );
            }

            // 3. Recompute each touched invoice's amount_paid from the
            // CURRENT set of all live applications (across all receipts).
            // This naturally unwinds whatever the old application was and
            // applies the new one in one step.
            $newAccountIds = $entry->lines->pluck('account_id')->all();
            foreach (array_unique(array_merge($oldAccountIds, $newAccountIds)) as $id) {
                Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
            }

            foreach ($touchedInvoiceIds as $invoiceId) {
                $invoice = Invoice::withoutGlobalScopes()->find($invoiceId);
                if (! $invoice) {
                    continue;
                }
                $this->recomputeInvoicePaidFromAllReceipts($invoice);
            }

            // Safety net for anything the explicit list missed (a caller that
            // rewrote applications without going through SaveReceipt, or drift
            // left behind before previous applications were tracked).
            $contactIds = [(int) $receipt->contact_id, $receipt->previousContactId()];
            $this->recomputeDriftedInvoicesForContacts((int) $receipt->company_id, $contactIds);

            $receipt->contact->recomputeArBalance();
            if ($receipt->previousContactId() !== null && $receipt->previousContactId() !== (int) $receipt->contact_id) {
                Contact::withoutGlobalScopes()->find($receipt->previousContactId())?->recomputeArBalance();
            }

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $receipt->company_id,
                AuditAction::CustomerReceiptReposted,
                $receipt,
                [
                    'receipt_no' => $receipt->receipt_no,
                    'amount_cents' => (int) $receipt->amount_cents,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        })));
    }

    /**
     * Sum applications across all posted, non-void receipts targeting this
     * invoice. Updates amount_paid_cents and status. This is the safe
     * canonical recompute — no matter what mutations happen on receipts,
     * the invoice ends up consistent with the ledger of applications.
     * Public so integrity:check --fix can repair a drifted cache.
     */
    public function recomputeInvoicePaidFromAllReceipts(Invoice $invoice): void
    {
        $invoice->forceFill([
            'amount_paid_cents' => $this->expectedPaidCents($invoice),
        ])->save();

        $this->refreshInvoiceStatus($invoice);
        $invoice->contact?->recomputeArBalance();
    }

    /**
     * What amount_paid_cents SHOULD be: live applications from posted receipts,
     * capped at the total. A plain query so it reads the same in a request, a
     * queued job and a console command regardless of the bound company.
     */
    public function expectedPaidCents(Invoice $invoice): int
    {
        $paid = (int) DB::table('receipt_applications as ra')
            ->join('customer_receipts as r', 'r.id', '=', 'ra.customer_receipt_id')
            ->where('ra.invoice_id', $invoice->id)
            ->where('r.status', ReceiptStatus::Posted->value)
            ->whereNull('r.deleted_at')
            ->sum('ra.amount_cents');

        return min($paid, (int) $invoice->total_cents);
    }

    /**
     * Recompute every posted/partial/paid invoice of the given contacts whose
     * cached paid amount disagrees with its live applications. One grouped
     * query finds the drift, so this stays cheap however long the contact's
     * history is; only the drifted rows are touched. Returns how many.
     *
     * @param  array<int, int|null>  $contactIds
     */
    public function recomputeDriftedInvoicesForContacts(int $companyId, array $contactIds): int
    {
        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        if ($contactIds === []) {
            return 0;
        }

        $fixed = 0;
        foreach ($this->driftedInvoiceRows($companyId, $contactIds) as $row) {
            $invoice = Invoice::withoutGlobalScopes()->find((int) $row->id);
            if ($invoice) {
                $this->recomputeInvoicePaidFromAllReceipts($invoice);
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Posted/partial/paid invoices whose cached amount_paid_cents differs from
     * what their live applications (posted, non-deleted receipts) say, capped
     * at the total. One grouped query; each row carries id, invoice_no,
     * total_cents, amount_paid_cents and live_cents (the uncapped sum).
     *
     * @param  list<int>|null  $contactIds  null = every contact in the company
     * @return Collection<int, \stdClass>
     */
    public function driftedInvoiceRows(int $companyId, ?array $contactIds = null): Collection
    {
        return $this->invoicePaidCacheQuery($companyId, $contactIds)
            ->whereRaw('i.amount_paid_cents <> CASE WHEN COALESCE(x.live_cents, 0) > i.total_cents THEN i.total_cents ELSE COALESCE(x.live_cents, 0) END')
            ->get();
    }

    /**
     * Invoices whose live applications exceed their total — more money applied
     * than was billed. The cache repair caps at the total, so this can only be
     * resolved by editing the receipts; it is reported, never "fixed".
     *
     * @return Collection<int, \stdClass>
     */
    public function overAppliedInvoiceRows(int $companyId): Collection
    {
        return $this->invoicePaidCacheQuery($companyId)
            ->whereRaw('COALESCE(x.live_cents, 0) > i.total_cents')
            ->get();
    }

    /**
     * @param  list<int>|null  $contactIds
     */
    private function invoicePaidCacheQuery(int $companyId, ?array $contactIds = null): Builder
    {
        $live = DB::table('receipt_applications as ra')
            ->join('customer_receipts as r', 'r.id', '=', 'ra.customer_receipt_id')
            ->where('r.status', ReceiptStatus::Posted->value)
            ->whereNull('r.deleted_at')
            ->groupBy('ra.invoice_id')
            ->selectRaw('ra.invoice_id, SUM(ra.amount_cents) as live_cents');

        $query = DB::table('invoices as i')
            ->leftJoinSub($live, 'x', 'x.invoice_id', '=', 'i.id')
            ->where('i.company_id', $companyId)
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value, InvoiceStatus::Paid->value])
            ->orderBy('i.id')
            ->select(['i.id', 'i.invoice_no', 'i.total_cents', 'i.amount_paid_cents'])
            ->selectRaw('COALESCE(x.live_cents, 0) as live_cents');

        if ($contactIds !== null) {
            $query->whereIn('i.contact_id', $contactIds);
        }

        return $query;
    }

    public function void(CustomerReceipt $receipt, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($receipt, $voidDate) {
            $receipt->loadMissing('journalEntry', 'applications.invoice');

            if (! $receipt->journal_entry_id) {
                throw new RuntimeException('Receipt is not posted.');
            }

            if ($receipt->status === ReceiptStatus::Void) {
                throw new RuntimeException('Receipt is already voided.');
            }

            $this->journalPoster->void($receipt->journalEntry, $voidDate, "Void of receipt {$receipt->receipt_no}");

            // Void first, then recompute each applied invoice from the ledger of
            // live applications — the same canonical formula repost() and
            // integrity:check use — instead of subtracting from a cache that
            // may itself be stale.
            $receipt->forceFill([
                'status' => ReceiptStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            foreach ($receipt->applications as $app) {
                if ($app->invoice) {
                    $this->recomputeInvoicePaidFromAllReceipts($app->invoice);
                }
            }

            $receipt->contact->recomputeArBalance();

            $this->auditRecorder->record(
                (int) $receipt->company_id,
                AuditAction::CustomerReceiptVoided,
                $receipt,
                [
                    'receipt_no' => $receipt->receipt_no,
                    'voided_at' => optional($receipt->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $receipt->journal_entry_id,
                ],
                $receipt->journalEntry,
            );
        }));
    }

    protected function applyToInvoices(CustomerReceipt $receipt): void
    {
        foreach ($receipt->applications as $app) {
            // Re-fetch the invoice under a row lock instead of incrementing the
            // in-memory relation. Two receipts posting against the same invoice
            // concurrently would otherwise read the same amount_paid_cents and
            // one write would clobber the other (lost update). lockForUpdate
            // serializes them so each adds to the latest committed value. (It is
            // a no-op on SQLite, which runs serially in tests anyway.)
            $invoice = Invoice::withoutGlobalScopes()
                ->whereKey($app->invoice_id)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                continue;
            }

            $newPaid = (int) $invoice->amount_paid_cents + (int) $app->amount_cents;

            $invoice->forceFill([
                'amount_paid_cents' => min($newPaid, (int) $invoice->total_cents),
            ])->save();

            $this->refreshInvoiceStatus($invoice);
        }
    }

    protected function refreshInvoiceStatus(Invoice $invoice): void
    {
        if ($invoice->balanceCents() <= 0 && $invoice->settledCents() > 0) {
            $invoice->status = InvoiceStatus::Paid;
        } elseif ($invoice->settledCents() > 0) {
            $invoice->status = InvoiceStatus::Partial;
        } else {
            $invoice->status = InvoiceStatus::Posted;
        }

        $invoice->save();
    }

    /**
     * Validate the receipt amount and applications.
     *
     * Ordinary receipts must be positive. A refund receipt — one linked to a
     * credit memo, recording money paid back to the customer via the debit
     * machine — must be negative and apply to no invoices; its negative AR
     * credit posts a balanced entry that debits AR and credits Undeposited
     * Funds, clearing the customer's credit.
     */
    protected function assertAmountValid(CustomerReceipt $receipt): void
    {
        $amount = (int) $receipt->amount_cents;

        if ($amount === 0) {
            throw new RuntimeException('Receipt amount cannot be zero.');
        }

        if ($receipt->isRefund()) {
            if ($amount > 0) {
                throw new RuntimeException('Refund receipt amount must be negative.');
            }

            if ($receipt->applications->isNotEmpty()) {
                throw new RuntimeException('A refund receipt cannot be applied to invoices.');
            }

            return;
        }

        if ($amount < 0) {
            throw new RuntimeException('Receipt amount must be positive.');
        }

        $totalApplied = (int) $receipt->applications->sum('amount_cents');

        if ($totalApplied > $amount) {
            throw new RuntimeException('Applied amount exceeds receipt total.');
        }
    }

    /**
     * Build the journal entry for a receipt. A positive (ordinary) receipt debits
     * the deposit-to account and credits AR; a negative (refund) receipt flips
     * both sides, keeping the entry balanced with positive magnitudes.
     *
     * For a foreign receipt the deposit lands in home cents at the receipt's rate,
     * while AR is cleared at each settled invoice's original rate (so the foreign
     * AR control nets to zero). The home-cents difference between cash received
     * and AR cleared is the realized exchange gain/loss.
     */
    protected function buildReceiptLines(JournalEntry $entry, CustomerReceipt $receipt, Account $arAccount): void
    {
        if (! $receipt->isForeignCurrency()) {
            $this->buildHomeReceiptLines($entry, $receipt, $arAccount->id);

            return;
        }

        $this->buildForeignReceiptLines($entry, $receipt, $arAccount);
    }

    protected function buildHomeReceiptLines(JournalEntry $entry, CustomerReceipt $receipt, int $arAccountId): void
    {
        $amount = (int) $receipt->amount_cents;

        $entry->lines()->create([
            'account_id' => $receipt->deposit_to_account_id,
            'debit_cents' => max($amount, 0),
            'credit_cents' => max(-$amount, 0),
            'memo' => BankLineMemo::forSource($receipt),
            'line_order' => 0,
        ]);

        $entry->lines()->create([
            'account_id' => $arAccountId,
            'debit_cents' => max(-$amount, 0),
            'credit_cents' => max($amount, 0),
            'memo' => 'AR — '.$receipt->contact->display_name,
            'contact_id' => $receipt->contact_id,
            'line_order' => 1,
        ]);
    }

    /**
     * Deposit debits the home value received at the receipt rate; AR is credited
     * per application at each invoice's locked rate (plus the unapplied remainder
     * at the receipt rate). The home-cents residual balances to Exchange Gain/Loss.
     */
    protected function buildForeignReceiptLines(JournalEntry $entry, CustomerReceipt $receipt, Account $arAccount): void
    {
        $amount = (int) $receipt->amount_cents;
        $currency = mb_strtoupper((string) $receipt->currency_code);
        $ratePay = $this->lockReceiptRate($receipt);

        $depositHome = Currency::toHomeCents($amount, $ratePay);

        $entry->lines()->create([
            'account_id' => $receipt->deposit_to_account_id,
            'debit_cents' => max($depositHome, 0),
            'credit_cents' => max(-$depositHome, 0),
            'memo' => BankLineMemo::forSource($receipt),
            'line_order' => 0,
        ]);

        $order = 1;
        $arNetCreditHome = 0; // home cents credited to AR (negative = net debit)
        $appliedForeign = 0;

        foreach ($receipt->applications as $application) {
            $foreign = (int) $application->amount_cents;
            $invoiceRate = (string) ($application->invoice?->fx_rate ?? $ratePay);
            $home = Currency::toHomeCents($foreign, $invoiceRate);
            $appliedForeign += $foreign;
            $arNetCreditHome += $home;

            $entry->lines()->create([
                'account_id' => $arAccount->id,
                'debit_cents' => 0,
                'credit_cents' => $home,
                'memo' => 'AR — '.$receipt->contact->display_name,
                'contact_id' => $receipt->contact_id,
                'line_order' => $order++,
                ...Currency::lineMemo($currency, $invoiceRate, 0, $foreign),
            ]);
        }

        // Unapplied remainder (on-account credit, or the whole of a refund) at the
        // receipt rate. Signed so refunds (negative) debit AR.
        $remainderForeign = $amount - $appliedForeign;

        if ($remainderForeign !== 0) {
            $home = Currency::toHomeCents($remainderForeign, $ratePay);
            $arNetCreditHome += $home;

            $entry->lines()->create([
                'account_id' => $arAccount->id,
                'debit_cents' => max(-$home, 0),
                'credit_cents' => max($home, 0),
                'memo' => 'AR — '.$receipt->contact->display_name,
                'contact_id' => $receipt->contact_id,
                'line_order' => $order++,
                ...Currency::lineMemo($currency, $ratePay, max(-$remainderForeign, 0), max($remainderForeign, 0)),
            ]);
        }

        // Realized FX residual: the amount needed to balance the entry in home
        // cents goes to Exchange Gain/Loss (debit = loss, credit = gain).
        $residual = $arNetCreditHome - $depositHome;

        if ($residual !== 0) {
            $entry->lines()->create([
                'account_id' => $this->exchangeGainLossAccountId($receipt),
                'debit_cents' => max($residual, 0),
                'credit_cents' => max(-$residual, 0),
                'memo' => 'Realized exchange '.($residual < 0 ? 'gain' : 'loss'),
                'line_order' => $order++,
            ]);
        }
    }

    /**
     * Lock the receipt's exchange rate (reused on repost) and cache the home value.
     */
    protected function lockReceiptRate(CustomerReceipt $receipt): string
    {
        if ($receipt->fx_rate !== null) {
            return (string) $receipt->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $receipt->company,
            (string) $receipt->currency_code,
            CarbonImmutable::parse($receipt->receipt_date),
        );

        $receipt->forceFill([
            'fx_rate' => $rate,
            'home_amount_cents' => Currency::toHomeCents((int) $receipt->amount_cents, $rate),
        ])->save();

        return $rate;
    }

    protected function exchangeGainLossAccountId(CustomerReceipt $receipt): int
    {
        $accountId = $receipt->company->exchange_gain_loss_account_id;

        if ($accountId === null) {
            throw new RuntimeException("Company {$receipt->company_id} has no Exchange Gain/Loss account; enable a foreign currency first.");
        }

        return (int) $accountId;
    }
}
