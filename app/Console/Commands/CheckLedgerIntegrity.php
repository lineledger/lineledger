<?php

namespace App\Console\Commands;

use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Invoice;
use App\Notifications\LedgerIntegrityAlert;
use App\Services\Audit\AuditMute;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Nightly proof that the books still reconcile. Four checks per company:
 *
 *   1. Audit hash chain — delegated to {@see VerifyAccountingAuditCommand}.
 *   2. Double-entry balance — every posted journal nets to zero across the GL,
 *      so SUM(debit_cents - credit_cents) over all posted lines must be 0.
 *   3. Balance-cache drift — accounts.balance_cents is a denormalized hint that
 *      reports ignore, but drift signals a posting bug, so we recompute each
 *      account from its posted lines and compare. --fix heals the cache.
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

    protected $description = 'Verify ledger integrity: audit hash chain, double-entry balance, account-balance cache, and invoice/bill paid caches.';

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

            $cached = (int) $account->balance_cents;

            if ($cached === $expected) {
                continue;
            }

            // Drift is always reported, --fix or not: it's a real signal (a posting
            // bug or tampering), and healing it in the same run must never absorb
            // that signal silently. --fix only changes whether the cache is also
            // corrected here, not whether the drift gets surfaced.
            if ($fix) {
                $account->forceFill(['balance_cents' => $expected])->saveQuietly();

                $issues[] = sprintf(
                    'Account %s (%s) balance cache was %d, recomputed to %d, and has been healed (--fix).',
                    $account->code,
                    $account->name,
                    $cached,
                    $expected,
                );

                continue;
            }

            $issues[] = sprintf(
                'Account %s (%s) balance cache is %d but recomputes to %d.',
                $account->code,
                $account->name,
                $cached,
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
