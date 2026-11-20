<?php

namespace App\Console\Commands;

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Enums\DeletableDocumentType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Invoice;
use App\Notifications\LedgerIntegrityAlert;
use App\Services\Audit\AuditMute;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\ReceiptPoster;
use App\Support\Accounting\ControlAccountRoles;
use App\Support\AlertRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Nightly proof that the books still reconcile. Six checks per company:
 *
 *   1. Audit hash chain — delegated to {@see VerifyAccountingAuditCommand}.
 *   2. Double-entry balance — every posted journal nets to zero across the GL,
 *      so SUM(debit_cents - credit_cents) over all posted lines must be 0.
 *   3. Balance-cache drift — accounts.balance_cents is a denormalized hint that
 *      reports ignore, but drift signals a posting bug, so we recompute each
 *      account from its posted lines and compare. --fix heals the cache.
 *   4. Document paid-cache drift — invoices/bills amount_paid_cents against the
 *      live applications of posted receipts and payments. --fix reposts.
 *   5. Tax on a control-account cheque line — report-only; see
 *      {@see checkControlAccountTax()}.
 *   6. Foreign units left by a void — report-only; see
 *      {@see checkVoidForeignUnits()}.
 *   7. A journal entry whose source document no longer exists, and
 *   8. a posted or voided document whose journal entry no longer exists —
 *      report-only; see {@see checkDocumentEntryLinks()}. Deleting goes through
 *      TransactionDeleter, which removes both together; either half alone
 *      means something deleted around it.
 *
 * On any failure it logs, emails ops (unless --no-alert), and exits non-zero so
 * a scheduler/CI run surfaces it. The accumulating run history is also exactly
 * the "we monitor that controls operate" evidence a SOC 2 Type II audit wants.
 */
class CheckLedgerIntegrity extends Command
{
    protected $signature = 'integrity:check
        {company? : Company ID; checks all companies when omitted}
        {--fix : Recompute drifted account-balance and document paid caches in place}
        {--no-alert : Report failures without emailing}
        {--no-log : Report failures without writing them to the application log (for a caller that reports them itself)}';

    protected $description = 'Verify ledger integrity: audit hash chain, double-entry balance, account-balance cache, invoice/bill paid caches, tax on control-account cheque lines, foreign-currency units left behind by voids, and documents and journal entries left without each other.';

    /**
     * Voided entries that check 6 lists one by one; any beyond are counted in
     * one closing line, so a book that has not been backfilled yet does not
     * bury the rest of the alert.
     */
    private const VOID_FOREIGN_UNITS_LISTED = 20;

    /**
     * Length of the rolling full-verification cycle, in days.
     *
     * The chain check normally resumes from each company's saved checkpoint, so
     * a nightly run only costs O(rows written today). That alone would never
     * re-examine already-verified history, so a shard of companies is fully
     * re-walked from genesis each night: every chain gets an unconditional
     * end-to-end audit within this many days, at 1/N of the cost per night.
     */
    private const FULL_SWEEP_CYCLE_DAYS = 30;

    public function handle(): int
    {
        $companyArg = $this->argument('company');

        $companyIds = $companyArg !== null
            ? [(int) $companyArg]
            : Company::query()->orderBy('id')->pluck('id')->all();

        if ($companyIds === []) {
            $this->info('No companies to check.');

            return self::SUCCESS;
        }

        $issuesByCompany = [];

        foreach ($companyIds as $companyId) {
            $issues = $this->checkCompany((int) $companyId, $companyArg !== null);

            $this->line(sprintf('Company %d — %d issue(s).', $companyId, count($issues)));

            foreach ($issues as $issue) {
                $this->error('  - '.$issue);
            }

            if ($issues !== []) {
                $issuesByCompany[$companyId] = $issues;
            }
        }

        if ($issuesByCompany === []) {
            $this->info('Ledger integrity OK.');

            return self::SUCCESS;
        }

        if (! $this->option('no-log')) {
            Log::error('Ledger integrity check failed.', $issuesByCompany);
        }

        if (! $this->option('no-alert')) {
            $this->sendAlert($issuesByCompany);
        }

        $this->error('Ledger integrity check FAILED.');

        return self::FAILURE;
    }

    /**
     * @param  bool  $explicit  True when the operator named this company on the
     *                          command line, which always gets the full walk —
     *                          someone checking one company by hand wants the
     *                          thorough answer, not the incremental one.
     * @return list<string>
     */
    protected function checkCompany(int $companyId, bool $explicit = false): array
    {
        $issues = [];

        // 1. Audit hash chain (reuse the dedicated verifier). --no-alert so this
        // command owns the single nightly email; audit:verify doesn't also send one.
        $verifyArgs = ['company' => $companyId, '--no-alert' => true];

        if ($this->shouldFullyVerify($companyId, $explicit)) {
            $verifyArgs['--full'] = true;
        }

        if ($this->callSilent('audit:verify', $verifyArgs) !== self::SUCCESS) {
            $issues[] = 'Audit hash chain failed verification (run `audit:verify '.$companyId.' --full` for detail).';
        }

        // 2. Double-entry balance across all posted lines.
        $net = (int) DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.is_posted', true)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_cents - journal_lines.credit_cents), 0) as net')
            ->value('net');

        if ($net !== 0) {
            $issues[] = "General ledger is out of balance by {$net} cents (posted debits minus credits should be zero).";
        }

        // 3. Account-balance cache drift.
        $issues = array_merge($issues, $this->checkBalanceCache($companyId));

        // 4. Document paid-cache drift: invoices.amount_paid_cents and
        //    bills.amount_paid_cents are denormalized from the live
        //    applications of posted receipts / payments. A stale value shows a
        //    document as paid (or owing) that the ledger says otherwise.
        $issues = array_merge($issues, $this->checkPaidCaches($companyId));

        // 5. Sales tax on a cheque line coded to an AR/AP control account.
        $issues = array_merge($issues, $this->checkControlAccountTax($companyId));

        // 6. Voided entries whose reversal leaves foreign-currency units behind.
        $issues = array_merge($issues, $this->checkVoidForeignUnits($companyId));

        // 7 + 8. Documents and their journal entries, each missing the other.
        $issues = array_merge($issues, $this->checkDocumentEntryLinks($companyId));

        return $issues;
    }

    /**
     * For every transaction type Delete covers: a journal entry still naming a
     * document that is gone (7), and a posted or voided document whose entry is
     * gone (8). TransactionDeleter removes a document and its entries in one
     * transaction, so either half on its own means a path deleted around it.
     * A soft-deleted document still counts as present.
     *
     * Report-only, with no --fix: which half is wrong needs a look by hand.
     *
     * @return list<string>
     */
    protected function checkDocumentEntryLinks(int $companyId): array
    {
        $issues = [];

        foreach (DeletableDocumentType::cases() as $type) {
            // A journal entry is its own document, and estimates and orders
            // never post: neither half can go missing.
            $draftStatus = $type->draftStatus();

            if ($draftStatus === null) {
                continue;
            }

            $class = $type->modelClass();
            $table = (new $class)->getTable();

            $orphanEntries = DB::table('journal_entries as e')
                ->where('e.company_id', $companyId)
                ->where('e.source_type', $class)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($table.' as d')->whereColumn('d.id', 'e.source_id'))
                ->orderBy('e.id')
                ->pluck('e.entry_no');

            $noun = str_replace('_', ' ', $type->value);

            if ($orphanEntries->isNotEmpty()) {
                $issues[] = sprintf(
                    'Journal entries whose %s no longer exists (%d): %s.',
                    $noun,
                    $orphanEntries->count(),
                    $orphanEntries->take(20)->implode(', '),
                );
            }

            $unlinked = DB::table($table.' as d')
                ->where('d.company_id', $companyId)
                ->whereNull('d.deleted_at')
                ->where('d.status', '!=', $draftStatus)
                ->where(fn ($q) => $q->whereNull('d.journal_entry_id')
                    ->orWhereNotExists(fn ($q) => $q->select(DB::raw(1))->from('journal_entries as e')->whereColumn('e.id', 'd.journal_entry_id')))
                ->orderBy('d.id')
                ->pluck('d.'.$type->numberColumn());

            if ($unlinked->isNotEmpty()) {
                $issues[] = sprintf(
                    'Posted or voided %ss with no journal entry (%d): %s.',
                    $noun,
                    $unlinked->count(),
                    $unlinked->take(20)->implode(', '),
                );
            }
        }

        return $issues;
    }

    /**
     * Voided entries that their reversal does not cancel in foreign units.
     *
     * A void mirrors every line, the foreign-currency memo included, so on each
     * account a voided entry and its reversal net to zero in foreign units just
     * as they do in home cents. A pair that does not leaves phantom foreign
     * units behind: every period-end Home Currency Adjustment revalues them into
     * an unrealized gain or loss, and the AR/AP reconcilers, which compare a
     * foreign customer or vendor in foreign units, misread what is owed.
     *
     * Report-only, with no --fix: nothing here is a cache to recompute. Voids
     * written before reversals carried the memo are repaired by
     * `currency:backfill-reversal-memos`, which app:upgrade runs; a reversal it
     * lists as skipped no longer mirrors its original and needs a look by hand.
     *
     * @return list<string>
     */
    protected function checkVoidForeignUnits(int $companyId): array
    {
        // One leg per side of each void pair, keyed by the voided entry. Lines
        // with no foreign amount add nothing to the sum, so they are left out.
        $originals = DB::table('journal_entries as je')
            ->join('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->where('je.company_id', $companyId)
            ->whereNotNull('je.voided_at')
            ->whereNotNull('je.reversed_by_entry_id')
            ->where(fn ($q) => $q->where('jl.foreign_debit_cents', '!=', 0)->orWhere('jl.foreign_credit_cents', '!=', 0))
            ->select('je.id as entry_id', 'jl.account_id')
            ->selectRaw('jl.foreign_debit_cents - jl.foreign_credit_cents as net');

        $reversals = DB::table('journal_entries as je')
            ->join('journal_entries as rev', fn ($join) => $join
                ->on('rev.id', '=', 'je.reversed_by_entry_id')
                ->on('rev.company_id', '=', 'je.company_id'))
            ->join('journal_lines as jl', 'jl.journal_entry_id', '=', 'rev.id')
            ->where('je.company_id', $companyId)
            ->whereNotNull('je.voided_at')
            ->where(fn ($q) => $q->where('jl.foreign_debit_cents', '!=', 0)->orWhere('jl.foreign_credit_cents', '!=', 0))
            ->select('je.id as entry_id', 'jl.account_id')
            ->selectRaw('jl.foreign_debit_cents - jl.foreign_credit_cents as net');

        $rows = DB::query()
            ->fromSub($originals->unionAll($reversals), 'pair_lines')
            ->select('entry_id', 'account_id')
            ->selectRaw('SUM(net) as net')
            ->groupBy('entry_id', 'account_id')
            ->havingRaw('SUM(net) <> 0')
            ->orderBy('entry_id')
            ->orderBy('account_id')
            ->get()
            ->groupBy('entry_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $listed = $rows->take(self::VOID_FOREIGN_UNITS_LISTED);

        $entries = DB::table('journal_entries as je')
            ->join('journal_entries as rev', 'rev.id', '=', 'je.reversed_by_entry_id')
            ->whereIntegerInRaw('je.id', $listed->keys()->map(fn ($id): int => (int) $id)->all())
            ->get(['je.id', 'je.entry_no', 'rev.id as reversal_id', 'rev.entry_no as reversal_no'])
            ->keyBy('id');

        $accounts = DB::table('accounts')
            ->whereIntegerInRaw('id', $listed->flatten(1)->pluck('account_id')->map(fn ($id): int => (int) $id)->unique()->values()->all())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $issues = [];

        foreach ($listed as $entryId => $accountRows) {
            $entry = $entries->get($entryId);

            $left = $accountRows
                ->map(fn ($row): string => sprintf(
                    '%s (%s) %+d',
                    $accounts->get($row->account_id)->code ?? '#'.$row->account_id,
                    $accounts->get($row->account_id)->name ?? '?',
                    (int) $row->net,
                ))
                ->implode(', ');

            $issues[] = sprintf(
                'Voided entry %s (#%d) and its reversal %s (#%d) do not cancel in foreign units: %s foreign cents are left over. Run `currency:backfill-reversal-memos %d` (app:upgrade runs it); if it lists this reversal as skipped, check it by hand.',
                $entry->entry_no ?? '?',
                (int) $entryId,
                $entry->reversal_no ?? '?',
                (int) ($entry->reversal_id ?? 0),
                $left,
                $companyId,
            );
        }

        if ($rows->count() > $listed->count()) {
            $issues[] = sprintf(
                '%d more void pair(s) leave foreign units behind as well. Run `currency:backfill-reversal-memos %d` (app:upgrade runs it).',
                $rows->count() - $listed->count(),
                $companyId,
            );
        }

        return $issues;
    }

    /**
     * Cheques posted with sales tax on a line coded to an AR/AP control account.
     *
     * The balance such a line settles already includes the tax its originating
     * invoice or bill recorded, so taxing it again double-counts: a
     * non-recoverable code is grossed up into the AR/AP leg itself (moving the
     * contact's sub-ledger by more than the payment), and a recoverable one adds
     * an input-tax-credit leg for a credit that was never incurred. The write
     * path now strips it ({@see ControlAccountRoles::excludesTax()}); this finds
     * what was posted before it did.
     *
     * Report-only, with no --fix: the repair is to open the cheque and save it,
     * which reposts through SaveCheque and drops the tax deliberately, under the
     * audit chain. A void needs nothing — its entry is already reversed.
     *
     * @return list<string>
     */
    protected function checkControlAccountTax(int $companyId): array
    {
        $cheques = DB::table('cheque_lines as cl')
            ->join('cheques as c', 'c.id', '=', 'cl.cheque_id')
            ->join('accounts as a', 'a.id', '=', 'cl.account_id')
            ->where('c.company_id', $companyId)
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.journal_entry_id')
            ->where('c.status', '!=', ChequeStatus::Void->value)
            ->whereIn('a.subtype', [AccountSubtype::AccountsReceivable->value, AccountSubtype::AccountsPayable->value])
            ->where(fn ($q) => $q->where('cl.tax_cents', '!=', 0)->orWhere('cl.secondary_tax_cents', '!=', 0))
            ->distinct()
            ->orderBy('c.id')
            ->pluck('c.cheque_no', 'c.id');

        $issues = [];

        foreach ($cheques as $id => $chequeNo) {
            $issues[] = "Cheque {$chequeNo} (id {$id}) carries sales tax on an Accounts Receivable / Payable line, which double-counts the tax already in that balance. Open it and save it to strip the tax and repost.";
        }

        return $issues;
    }

    /**
     * Whether this company's chain gets an unconditional genesis-to-tip walk on
     * this run, rather than resuming from its checkpoint.
     *
     * The shard rotates on a monotonic day counter rather than the calendar day
     * of month, so residues advance by exactly one per day and never skip:
     * day-of-month would stall on 31-day months and jump at year boundaries,
     * leaving some shards unswept for weeks.
     */
    protected function shouldFullyVerify(int $companyId, bool $explicit): bool
    {
        if ($explicit) {
            return true;
        }

        $day = intdiv(now()->getTimestamp(), 86400);

        return $companyId % self::FULL_SWEEP_CYCLE_DAYS === $day % self::FULL_SWEEP_CYCLE_DAYS;
    }

    /**
     * @return list<string>
     */
    protected function checkBalanceCache(int $companyId): array
    {
        $issues = [];
        $fix = (bool) $this->option('fix');

        // One grouped pass over posted lines: account_id => signed natural balance.
        $sums = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.is_posted', true)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, COALESCE(SUM(journal_lines.debit_cents), 0) as debits, COALESCE(SUM(journal_lines.credit_cents), 0) as credits')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get(['id', 'code', 'name', 'normal_balance', 'balance_cents']);

        foreach ($accounts as $account) {
            $row = $sums->get($account->id);
            $debits = (int) ($row->debits ?? 0);
            $credits = (int) ($row->credits ?? 0);

            $expected = $account->normal_balance === NormalBalance::Debit
                ? $debits - $credits
                : $credits - $debits;

            if ((int) $account->balance_cents === $expected) {
                continue;
            }

            if ($fix) {
                $account->forceFill(['balance_cents' => $expected])->saveQuietly();

                continue;
            }

            $issues[] = sprintf(
                'Account %s (%s) balance cache is %d but recomputes to %d.',
                $account->code,
                $account->name,
                (int) $account->balance_cents,
                $expected,
            );
        }

        return $issues;
    }

    /**
     * Invoices and bills whose cached amount_paid_cents disagrees with the sum
     * of their live applications — one grouped query per document type, like
     * check 3. --fix recomputes them through the posters (which also refresh
     * the document status and the contact's AR/AP), silently like check 3 but
     * listed in the output. Applications that EXCEED a document's total are a
     * separate issue the cache repair cannot resolve, so they are always
     * reported.
     *
     * @return list<string>
     */
    protected function checkPaidCaches(int $companyId): array
    {
        $issues = [];
        $fix = (bool) $this->option('fix');
        $receipts = app(ReceiptPoster::class);
        $payments = app(BillPaymentPoster::class);

        foreach ($receipts->driftedInvoiceRows($companyId) as $row) {
            $expected = min((int) $row->live_cents, (int) $row->total_cents);

            if ($fix) {
                $invoice = Invoice::withoutGlobalScopes()->find((int) $row->id);
                if ($invoice) {
                    AuditMute::silence(fn () => $receipts->recomputeInvoicePaidFromAllReceipts($invoice));
                    $this->line(sprintf('  Repaired invoice %s (#%d): paid cache %d -> %d.', $row->invoice_no, $row->id, (int) $row->amount_paid_cents, $expected));
                    Log::info('Repaired invoice paid cache.', ['company_id' => $companyId, 'invoice_id' => (int) $row->id, 'from' => (int) $row->amount_paid_cents, 'to' => $expected]);
                }

                continue;
            }

            $issues[] = sprintf(
                'Invoice %s (#%d) paid cache is %d but its live receipt applications sum to %d (capped at the %d total: %d).',
                $row->invoice_no,
                $row->id,
                (int) $row->amount_paid_cents,
                (int) $row->live_cents,
                (int) $row->total_cents,
                $expected,
            );
        }

        foreach ($receipts->overAppliedInvoiceRows($companyId) as $row) {
            $issues[] = sprintf(
                'Invoice %s (#%d) has %d applied from receipts against a total of %d — an over-application that only editing the receipts can resolve.',
                $row->invoice_no,
                $row->id,
                (int) $row->live_cents,
                (int) $row->total_cents,
            );
        }

        foreach ($receipts->overRefundedReceiptRows($companyId) as $row) {
            $issues[] = sprintf(
                'Receipt %s (#%d) has %d applied and %d refunded against an amount of %d — more refunded than it left unapplied. Remove a refund or an application to resolve it.',
                $row->receipt_no,
                $row->id,
                (int) $row->applied_cents,
                (int) $row->refunded_cents,
                (int) $row->amount_cents,
            );
        }

        foreach ($payments->driftedBillRows($companyId) as $row) {
            $expected = min((int) $row->live_cents, (int) $row->total_cents);

            if ($fix) {
                $bill = Bill::withoutGlobalScopes()->find((int) $row->id);
                if ($bill) {
                    AuditMute::silence(fn () => $payments->recomputeBillPaidFromAllPayments($bill));
                    $this->line(sprintf('  Repaired bill %s (#%d): paid cache %d -> %d.', $row->bill_no, $row->id, (int) $row->amount_paid_cents, $expected));
                    Log::info('Repaired bill paid cache.', ['company_id' => $companyId, 'bill_id' => (int) $row->id, 'from' => (int) $row->amount_paid_cents, 'to' => $expected]);
                }

                continue;
            }

            $issues[] = sprintf(
                'Bill %s (#%d) paid cache is %d but its live payment applications sum to %d (capped at the %d total: %d).',
                $row->bill_no,
                $row->id,
                (int) $row->amount_paid_cents,
                (int) $row->live_cents,
                (int) $row->total_cents,
                $expected,
            );
        }

        foreach ($payments->overAppliedBillRows($companyId) as $row) {
            $issues[] = sprintf(
                'Bill %s (#%d) has %d applied from payments against a total of %d — an over-application that only editing the payments can resolve.',
                $row->bill_no,
                $row->id,
                (int) $row->live_cents,
                (int) $row->total_cents,
            );
        }

        return $issues;
    }

    /**
     * @param  array<int|string, list<string>>  $issuesByCompany
     */
    protected function sendAlert(array $issuesByCompany): void
    {
        $recipients = AlertRecipients::for('services.ledger_integrity.alert_email');

        if ($recipients !== []) {
            Notification::route('mail', $recipients)->notify(new LedgerIntegrityAlert($issuesByCompany));
            $this->line('Alert emailed to '.implode(', ', $recipients).'.');

            return;
        }

        $this->warn('No alert email configured (services.ledger_integrity.alert_email) and no site admin to fall back to; skipping email.');
    }
}
