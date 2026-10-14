<?php

namespace App\Console\Commands;

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
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
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Nightly proof that the books still reconcile. Five checks per company:
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
        {--no-alert : Report failures without emailing}';

    protected $description = 'Verify ledger integrity: audit hash chain, double-entry balance, account-balance cache, invoice/bill paid caches, and tax on control-account cheque lines.';

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

        Log::error('Ledger integrity check failed.', $issuesByCompany);

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
        $email = config('services.ledger_integrity.alert_email');

        if (is_string($email) && $email !== '') {
            Notification::route('mail', $email)->notify(new LedgerIntegrityAlert($issuesByCompany));
            $this->line("Alert emailed to {$email}.");

            return;
        }

        $this->warn('No alert email configured (services.ledger_integrity.alert_email); skipping email.');
    }
}
