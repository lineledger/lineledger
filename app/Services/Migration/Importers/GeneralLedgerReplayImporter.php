<?php

namespace App\Services\Migration\Importers;

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Migration\AccountResolver;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\Csv\StreamingGeneralLedgerReader;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use App\Services\Migration\QuickBooksDocumentReconstructor;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Full-history importer: replays every transaction from a QuickBooks Desktop
 * export (the "Journal" report as CSV, or a native IIF file) into the general
 * ledger as one balanced, posted JournalEntry per source transaction.
 *
 * This reproduces every financial statement faithfully without reconstructing
 * native invoice/bill documents. Idempotent: each entry is tagged with a
 * deterministic source_external_id so re-running a file skips what's already in.
 */
class GeneralLedgerReplayImporter implements Importer
{
    /** Transactions whose preview rows are returned for display (aggregates cover the whole file). */
    protected const PREVIEW_LIMIT = 200;

    /** Per-transaction debit/credit imbalance (cents) absorbed by the variance account. */
    protected const ROUNDING_TOLERANCE_CENTS = 2;

    /** Transactions processed between progress callbacks. */
    protected const PROGRESS_INTERVAL = 250;

    protected const VARIANCE_ACCOUNT_NAME = 'Conversion Rounding Variance';

    public function __construct(
        protected StreamingGeneralLedgerReader $reader,
        protected JournalPoster $journalPoster,
        protected QuickBooksDocumentReconstructor $reconstructor,
        protected ChartOfAccountsImporter $accountListing,
    ) {}

    public function templateHeaders(): array
    {
        return ['trans_no', 'type', 'date', 'num', 'name', 'memo', 'account', 'debit', 'credit'];
    }

    public function templateExampleRows(): array
    {
        // One transaction across two rows: the continuation (split) row leaves trans_no blank.
        return [
            ['trans_no' => '1001', 'type' => 'Invoice', 'date' => '2024-01-15', 'num' => 'INV-1042', 'name' => 'Acme Construction Ltd.', 'memo' => 'Job 12 progress billing', 'account' => 'Accounts Receivable', 'debit' => '1250.00', 'credit' => ''],
            ['trans_no' => '', 'type' => '', 'date' => '', 'num' => '', 'name' => 'Acme Construction Ltd.', 'memo' => 'Job 12 progress billing', 'account' => 'Construction Income', 'debit' => '', 'credit' => '1250.00'],
        ];
    }

    /**
     * @param  string|list<string>  $csvPaths
     */
    public function preview(string|array $csvPaths, ImportContext $ctx): ImportResult
    {
        $paths = array_values((array) $csvPaths);

        // Preview only the first file — enough to validate the format without a slow
        // full pass over a large multi-file set inside the request cycle.
        return $this->run($paths === [] ? [] : [$paths[0]], $ctx, dryRun: true);
    }

    /**
     * @param  string|list<string>  $csvPaths
     */
    public function commit(string|array $csvPaths, ImportContext $ctx, ?callable $onProgress = null): ImportResult
    {
        return $this->run(array_values((array) $csvPaths), $ctx, dryRun: false, onProgress: $onProgress);
    }

    /**
     * Replays every transaction across all given files into one ledger. Transactions
     * keep their own dates, so reports order chronologically regardless of file order;
     * shared idempotency hashes dedupe across overlapping files.
     *
     * @param  list<string>  $paths
     */
    protected function run(array $paths, ImportContext $ctx, bool $dryRun, ?callable $onProgress = null): ImportResult
    {
        // A full-history replay posts hundreds of thousands of entries; an enabled query
        // log would retain every statement and exhaust memory. Keep it off.
        DB::connection()->disableQueryLog();

        $format = $ctx->sourceFormat === StreamingGeneralLedgerReader::FORMAT_IIF
            ? StreamingGeneralLedgerReader::FORMAT_IIF
            : StreamingGeneralLedgerReader::FORMAT_CSV;

        // Full history reconstructs entries in the past, so the company must be unlocked.
        if (! $dryRun && $ctx->company->lock_date !== null) {
            return $this->result($dryRun, [], [['row' => 0, 'message' => "Company is locked at {$ctx->company->lock_date->toDateString()}. Full transaction history can only be replayed into an unlocked company."]]);
        }

        // Give auto-created accounts a real type instead of defaulting everything to
        // Other Asset. IIF files embed account types (!ACCNT records); CSV exports don't,
        // so the user can attach a QuickBooks Account Listing as the type source.
        $typeHints = [];
        $typeHintsByCode = [];
        if ($ctx->autoCreateAccounts) {
            if ($format === StreamingGeneralLedgerReader::FORMAT_IIF) {
                foreach ($paths as $path) {
                    $typeHints += $this->reader->accountTypes($path, $format);
                }
            } elseif ($ctx->accountTypesPath !== null) {
                $hints = $this->accountListing->typeHints($ctx->accountTypesPath);
                $typeHintsByCode = $hints['byCode'];
                $typeHints = $hints['byName'];
            }
        }

        $resolver = new AccountResolver($ctx->company->id, autoCreate: $ctx->autoCreateAccounts && ! $dryRun, typeHints: $typeHints, typeHintsByCode: $typeHintsByCode);
        $contacts = $this->contactMap($ctx->company->id);
        $existingHashes = $dryRun ? collect() : $this->existingHashes($ctx->company->id);
        $seenHashes = [];

        $nextSeq = $dryRun ? 0 : $this->startingSequence($ctx->company->id);
        $varianceAccountId = null;

        $errors = [];
        $preview = [];
        $createdIds = [];
        $affectedAccountIds = [];
        $unresolvedNames = [];
        $unmatchedNames = 0;

        $documentsCreated = 0;

        $stats = [
            'transactions' => 0, 'committed' => 0, 'skipped_duplicate' => 0, 'skipped_zero' => 0, 'lines' => 0,
            'unbalanced' => 0, 'rounding_adjusted' => 0, 'would_create_accounts' => 0,
            'ar_balance_cents' => 0, 'ap_balance_cents' => 0,
            'date_min' => null, 'date_max' => null,
        ];

        try {
            foreach ($paths as $path) {
                foreach ($this->reader->read($path, $format) as $block) {
                    $stats['transactions']++;
                    $rowRef = $stats['transactions'];

                    if ($block['lines'] === []) {
                        $this->addError($errors, $rowRef, 'Transaction has no postable lines.');

                        continue;
                    }

                    // Date.
                    $entryDate = $this->parseDate($block['date']);
                    if ($entryDate === null) {
                        $this->addError($errors, $rowRef, "Could not parse transaction date '".($block['date'] ?? '')."'.");

                        continue;
                    }
                    $stats['date_min'] = $stats['date_min'] === null ? $entryDate : min($stats['date_min'], $entryDate);
                    $stats['date_max'] = $stats['date_max'] === null ? $entryDate : max($stats['date_max'], $entryDate);

                    // Idempotency.
                    $hash = $this->hash($ctx->company->id, $block);
                    if ($existingHashes->has($hash) || isset($seenHashes[$hash])) {
                        $stats['skipped_duplicate']++;

                        continue;
                    }
                    $seenHashes[$hash] = true;

                    // Resolve accounts + balance.
                    $resolved = [];
                    $debitTotal = 0;
                    $creditTotal = 0;
                    $missing = false;

                    foreach ($block['lines'] as $line) {
                        $account = $resolver->find($line['account']);

                        if ($account === null) {
                            if ($ctx->autoCreateAccounts) {
                                if ($dryRun) {
                                    $stats['would_create_accounts']++;
                                    $unresolvedNames[mb_strtolower($line['account'])] = $line['account'];
                                    // Treat as resolvable for balance purposes in preview.
                                    $account = null;
                                } else {
                                    $account = $resolver->resolveOrCreate($line['account']);
                                }
                            } else {
                                $missing = true;
                                $unresolvedNames[mb_strtolower($line['account'])] = $line['account'];
                                $this->addError($errors, $rowRef, "Account '{$line['account']}' not found. Import it in the Chart of Accounts step, or enable auto-create.");

                                continue;
                            }
                        }

                        $stats['lines']++;
                        $debitTotal += $line['debit_cents'];
                        $creditTotal += $line['credit_cents'];

                        if ($account !== null) {
                            $this->accumulateControlBalance($stats, $account, $line['debit_cents'], $line['credit_cents']);
                        }

                        $resolved[] = ['account' => $account, 'line' => $line];
                    }

                    if ($missing) {
                        continue;
                    }

                    // Zero-dollar transactions (voided cheques, memo-only entries) have no GL
                    // effect — skip them rather than failing on a $0 journal entry.
                    if ($debitTotal === 0 && $creditTotal === 0) {
                        $stats['skipped_zero']++;

                        continue;
                    }

                    // Balance check + rounding tolerance.
                    $diff = $debitTotal - $creditTotal;
                    if ($diff !== 0) {
                        if (abs($diff) > self::ROUNDING_TOLERANCE_CENTS) {
                            $stats['unbalanced']++;
                            $this->addError($errors, $rowRef, 'Transaction does not balance: debits '.CsvParser::centsLabel($debitTotal).' vs credits '.CsvParser::centsLabel($creditTotal).'.');

                            continue;
                        }
                        $stats['rounding_adjusted']++;
                    }

                    // Contact link (header name, best-effort).
                    $contactId = $ctx->linkContactNames ? ($contacts[mb_strtolower((string) $block['name'])] ?? null) : null;
                    if ($ctx->linkContactNames && $block['name'] !== null && $contactId === null) {
                        $unmatchedNames++;
                    }

                    if (count($preview) < self::PREVIEW_LIMIT) {
                        $preview[] = [
                            'trans' => $block['key'],
                            'date' => $entryDate->toDateString(),
                            'type' => $block['type'],
                            'num' => $block['num'],
                            'name' => $block['name'],
                            'lines' => count($resolved),
                            'debit' => CsvParser::centsLabel($debitTotal),
                            'status' => $diff !== 0 ? 'rounding adjusted' : 'ok',
                        ];
                    }

                    if ($dryRun) {
                        continue;
                    }

                    // Commit this transaction atomically.
                    try {
                        $entryNo = 'JE-'.str_pad((string) (++$nextSeq), 6, '0', STR_PAD_LEFT);
                        $created = $this->postBlock($ctx, $block, $entryDate, $hash, $entryNo, $resolved, $diff, $contactId, $contacts, $resolver, $varianceAccountId, $affectedAccountIds, $documentsCreated);
                        $createdIds[] = $created;
                        $stats['committed']++;
                    } catch (Throwable $e) {
                        $nextSeq--; // reclaim the unused number
                        $this->addError($errors, $rowRef, 'Failed to post transaction: '.$e->getMessage());

                        continue;
                    }

                    if ($onProgress !== null && $stats['committed'] % self::PROGRESS_INTERVAL === 0) {
                        $onProgress($stats['committed']);
                    }
                }
            }
        } catch (Throwable $e) {
            // A malformed file (e.g. no recognisable header) surfaces as a single error
            // rather than an uncaught exception, so preview and commit behave consistently.
            $this->addError($errors, 0, $e->getMessage());
        }

        // One balance recomputation pass for every account we touched.
        if (! $dryRun && $affectedAccountIds !== []) {
            $this->journalPoster->recomputeAccounts(array_keys($affectedAccountIds));
        }

        // Apply reconstructed receipts/payments to their invoices/bills, oldest-first.
        if (! $dryRun && $ctx->reconstructDocuments && $documentsCreated > 0) {
            $this->applyPaymentsFifo($ctx->company->id);
        }
        $stats['documents_created'] = $documentsCreated;

        if ($onProgress !== null) {
            $onProgress($stats['committed']);
        }

        $stats['date_min'] = $stats['date_min']?->toDateString();
        $stats['date_max'] = $stats['date_max']?->toDateString();
        $stats['unresolved_accounts'] = count($unresolvedNames);
        $stats['unresolved_account_names'] = array_values($unresolvedNames);
        $stats['unmatched_names'] = $unmatchedNames;

        return $this->result($dryRun, $preview, $errors, $createdIds, $stats);
    }

    /**
     * @param  list<array{account: ?Account, line: array<string, mixed>}>  $resolved
     * @param  array<string, Contact>  $contacts
     * @param  array<int, bool>  $affectedAccountIds
     */
    protected function postBlock(
        ImportContext $ctx,
        array $block,
        CarbonImmutable $entryDate,
        string $hash,
        string $entryNo,
        array $resolved,
        int $diff,
        ?int $contactId,
        array $contacts,
        AccountResolver $resolver,
        ?int &$varianceAccountId,
        array &$affectedAccountIds,
        int &$documentsCreated,
    ): int {
        // The journal entry is posted in its own transaction so it always lands.
        $entry = DB::transaction(function () use ($ctx, $block, $entryDate, $hash, $entryNo, $resolved, $diff, $contactId, $contacts, $resolver, &$varianceAccountId, &$affectedAccountIds): JournalEntry {
            $entry = JournalEntry::withoutGlobalScopes()->create([
                'company_id' => $ctx->company->id,
                'entry_no' => $entryNo,
                'entry_date' => $entryDate->toDateString(),
                'memo' => $this->memoFor($block),
                'source_type' => 'qbd_import',
                'source_external_id' => $hash,
            ]);

            $order = 0;

            foreach ($resolved as $item) {
                /** @var Account $account */
                $account = $item['account'];
                $line = $item['line'];
                $lineContactId = $contactId;

                if ($ctx->linkContactNames && $line['name'] !== null) {
                    $lineContactId = $contacts[mb_strtolower((string) $line['name'])] ?? $contactId;
                }

                $entry->lines()->create([
                    'account_id' => $account->id,
                    'debit_cents' => $line['debit_cents'],
                    'credit_cents' => $line['credit_cents'],
                    'memo' => $line['memo'],
                    'contact_id' => $lineContactId,
                    'line_order' => $order++,
                ]);

                $affectedAccountIds[$account->id] = true;
            }

            // Absorb a within-tolerance rounding difference into the variance account.
            if ($diff !== 0) {
                $varianceAccountId ??= $resolver->ensure(self::VARIANCE_ACCOUNT_NAME, AccountSubtype::OtherExpense)->id;

                $entry->lines()->create([
                    'account_id' => $varianceAccountId,
                    'debit_cents' => $diff < 0 ? abs($diff) : 0,
                    'credit_cents' => $diff > 0 ? $diff : 0,
                    'memo' => 'Conversion rounding variance',
                    'line_order' => $order++,
                ]);

                $affectedAccountIds[$varianceAccountId] = true;
            }

            $entry = $entry->fresh();
            $this->journalPoster->post($entry, recompute: false);

            return $entry;
        });

        // Reconstruct a native document and link it to the entry. This is best-effort
        // and runs in its OWN transaction: a failure (e.g. a duplicate QuickBooks
        // document number) must never roll back the already-posted journal entry —
        // otherwise the transaction's GL impact (a cheque crediting the bank, etc.)
        // would be silently dropped.
        if ($ctx->reconstructDocuments) {
            try {
                $document = DB::transaction(fn () => $this->reconstructor->build($entry, $block, $resolved, $contactId));

                if ($document !== null) {
                    $documentsCreated++;
                }
            } catch (Throwable) {
                // Leave it as a plain journal entry; the GL is already correct.
            }
        }

        return (int) $entry->id;
    }

    /**
     * Apply reconstructed customer receipts to that customer's invoices, and bill
     * payments to that vendor's bills, oldest-first (the QuickBooks default). Driven
     * entirely by the database, one contact at a time, so it holds no model graph in
     * memory — a full-history import can reconstruct hundreds of thousands of documents.
     */
    protected function applyPaymentsFifo(int $companyId): void
    {
        $this->applyFifoSet(
            $companyId,
            paymentTable: 'customer_receipts', paymentDateCol: 'receipt_date',
            targetTable: 'invoices', targetDateCol: 'invoice_date',
            appTable: 'receipt_applications', paymentFk: 'customer_receipt_id', targetFk: 'invoice_id',
            paidStatus: InvoiceStatus::Paid->value, partialStatus: InvoiceStatus::Partial->value,
        );

        $this->applyFifoSet(
            $companyId,
            paymentTable: 'bill_payments', paymentDateCol: 'payment_date',
            targetTable: 'bills', targetDateCol: 'bill_date',
            appTable: 'bill_payment_applications', paymentFk: 'bill_payment_id', targetFk: 'bill_id',
            paidStatus: BillStatus::Paid->value, partialStatus: BillStatus::Partial->value,
        );
    }

    /**
     * FIFO-apply this import's payments to its targets, per contact, via raw queries.
     */
    protected function applyFifoSet(
        int $companyId,
        string $paymentTable,
        string $paymentDateCol,
        string $targetTable,
        string $targetDateCol,
        string $appTable,
        string $paymentFk,
        string $targetFk,
        string $paidStatus,
        string $partialStatus,
    ): void {
        // Contacts with import-created payments (linked to an entry tagged by this import).
        $contactIds = DB::table($paymentTable)
            ->join('journal_entries as je', 'je.id', '=', $paymentTable.'.journal_entry_id')
            ->where($paymentTable.'.company_id', $companyId)
            ->whereNull($paymentTable.'.deleted_at')
            ->whereNotNull('je.source_external_id')
            ->whereNotNull($paymentTable.'.contact_id')
            ->distinct()
            ->pluck($paymentTable.'.contact_id');

        foreach ($contactIds as $contactId) {
            $payments = DB::table($paymentTable)
                ->where('company_id', $companyId)->where('contact_id', $contactId)
                ->whereNull('deleted_at')->where('amount_cents', '>', 0)
                ->orderBy($paymentDateCol)->orderBy('id')
                ->get(['id', 'amount_cents']);

            $targets = DB::table($targetTable)
                ->where('company_id', $companyId)->where('contact_id', $contactId)
                ->whereNull('deleted_at')
                ->orderBy($targetDateCol)->orderBy('id')
                ->get(['id', 'total_cents', 'amount_paid_cents']);

            if ($payments->isEmpty() || $targets->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($payments, $targets, $appTable, $paymentFk, $targetFk, $targetTable, $paidStatus, $partialStatus): void {
                $cursor = 0;

                foreach ($payments as $payment) {
                    $remaining = (int) $payment->amount_cents;

                    while ($remaining > 0 && $cursor < $targets->count()) {
                        $target = $targets[$cursor];
                        $open = (int) $target->total_cents - (int) $target->amount_paid_cents;

                        if ($open <= 0) {
                            $cursor++;

                            continue;
                        }

                        $applied = min($remaining, $open);

                        DB::table($appTable)->insert([
                            $paymentFk => $payment->id,
                            $targetFk => $target->id,
                            'amount_cents' => $applied,
                        ]);

                        $newPaid = (int) $target->amount_paid_cents + $applied;
                        DB::table($targetTable)->where('id', $target->id)->update([
                            'amount_paid_cents' => $newPaid,
                            'status' => $newPaid >= (int) $target->total_cents ? $paidStatus : $partialStatus,
                        ]);

                        $target->amount_paid_cents = $newPaid;
                        $remaining -= $applied;

                        if ($newPaid >= (int) $target->total_cents) {
                            $cursor++;
                        }
                    }
                }
            });
        }
    }

    /**
     * Track the running AR/AP balance the replay produces, for reconciliation against
     * the open-invoice / open-bill steps.
     *
     * @param  array<string, mixed>  $stats
     */
    protected function accumulateControlBalance(array &$stats, Account $account, int $debit, int $credit): void
    {
        if ($account->subtype === AccountSubtype::AccountsReceivable) {
            $stats['ar_balance_cents'] += $debit - $credit;
        } elseif ($account->subtype === AccountSubtype::AccountsPayable) {
            $stats['ap_balance_cents'] += $credit - $debit;
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function memoFor(array $block): string
    {
        $parts = array_filter([
            $block['type'] ?? null,
            $block['num'] ? "#{$block['num']}" : null,
            $block['name'] ?? null,
        ]);

        $head = $parts === [] ? 'QuickBooks transaction' : implode(' ', $parts);
        $memo = $block['memo'] ?? null;

        return $memo ? "{$head} — {$memo}" : $head;
    }

    /**
     * Deterministic per-transaction key. Independent of account resolution so it is
     * stable across re-exports and across runs.
     *
     * @param  array<string, mixed>  $block
     */
    protected function hash(int $companyId, array $block): string
    {
        $lines = array_map(
            fn (array $l): string => mb_strtolower($l['account']).':'.$l['debit_cents'].':'.$l['credit_cents'],
            $block['lines'],
        );
        sort($lines);

        return hash('sha256', implode('|', [
            $companyId,
            $block['key'],
            $block['type'] ?? '',
            $block['num'] ?? '',
            $block['date'] ?? '',
            implode(';', $lines),
        ]));
    }

    protected function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, Contact>
     */
    protected function contactMap(int $companyId): array
    {
        $map = [];

        Contact::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get(['id', 'display_name'])
            ->each(function (Contact $c) use (&$map): void {
                if ($c->display_name !== null) {
                    $map[mb_strtolower($c->display_name)] = $c->id;
                }
            });

        return $map;
    }

    /**
     * @return Collection<string, bool>
     */
    protected function existingHashes(int $companyId)
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('source_external_id')
            ->pluck('source_external_id')
            ->flip();
    }

    protected function startingSequence(int $companyId): int
    {
        $last = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('entry_no', 'like', 'JE-%')
            ->orderByDesc('id')
            ->value('entry_no');

        if ($last !== null && preg_match('/JE-(\d+)/', $last, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * @param  list<array{row:int, message:string}>  $errors
     */
    protected function addError(array &$errors, int $row, string $message): void
    {
        // Cap surfaced errors so a badly-formed 30k-row file doesn't produce a huge payload.
        if (count($errors) < 100) {
            $errors[] = ['row' => $row, 'message' => $message];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $preview
     * @param  list<array{row:int, message:string}>  $errors
     * @param  list<int>  $createdIds
     * @param  array<string, mixed>  $summary
     */
    protected function result(bool $dryRun, array $preview, array $errors, array $createdIds = [], array $summary = []): ImportResult
    {
        return new ImportResult(
            isDryRun: $dryRun,
            previewRows: $preview,
            errors: $errors,
            createdIds: $createdIds,
            summary: $summary,
        );
    }
}
