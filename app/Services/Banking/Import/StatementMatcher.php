<?php

namespace App\Services\Banking\Import;

use App\Enums\BankStatementImportStatus;
use App\Enums\StatementLineMatchStatus;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pre-ticks a statement against the ledger. For each parsed line it finds the best
 * uncleared posted journal line on the same bank account and records the verdict —
 * but never touches the ledger itself. The user reviews on the reconcile screen and
 * the existing complete() path does the actual clearing.
 *
 * Sign convention: a statement line's amount_cents is a signed book-delta, compared
 * directly to a candidate's (debit_cents - credit_cents). Matching is greedy and
 * one-to-one (a book line is claimed by at most one statement line), highest
 * confidence first: exact (amount + date) → amount + near date → amount only.
 */
class StatementMatcher
{
    public function __construct(protected StatementSuggestionPipeline $suggestions) {}

    /**
     * @return array{matched: int, suggested: int, unmatched: int, duplicate: int}
     */
    public function match(BankStatementImport $import): array
    {
        $accountId = (int) $import->account_id;
        $tolerance = (int) config('banking.statement_import.match.date_tolerance_days', 4);

        $lines = $import->lines()->orderBy('txn_date')->orderBy('id')->get();

        $seenFingerprints = $this->committedFingerprints($import);
        $available = $this->candidateBookLines($accountId);

        $summary = ['matched' => 0, 'suggested' => 0, 'unmatched' => 0, 'duplicate' => 0];

        // First split out duplicates (already-committed, or repeated within this file)
        // so they never claim a candidate or get proposed as an add.
        $active = [];
        foreach ($lines as $line) {
            if (isset($seenFingerprints[$line->fingerprint])) {
                $this->record($line, StatementLineMatchStatus::Duplicate, null, null, __('Already imported in a previous statement.'));
                $summary['duplicate']++;

                continue;
            }
            $seenFingerprints[$line->fingerprint] = true;
            $active[] = $line;
        }

        $resolved = [];

        // Pass A — exact amount and date.
        foreach ($active as $i => $line) {
            $id = $this->claimExact($available, (int) $line->amount_cents, $line->txn_date);
            if ($id !== null) {
                $this->record($line, StatementLineMatchStatus::Matched, $id, 100, __('Exact amount and date.'));
                $summary['matched']++;
                $resolved[$i] = true;
            }
        }

        // Pass B — amount with date within tolerance; unique candidate auto-matches,
        // several candidates becomes a suggestion the user confirms.
        foreach ($active as $i => $line) {
            if (isset($resolved[$i])) {
                continue;
            }

            $near = $this->candidatesByAmountWithinDays($available, (int) $line->amount_cents, $line->txn_date, $tolerance);

            if (count($near) === 1) {
                $id = $this->claim($available, (int) $line->amount_cents, $near[0]['id']);
                $this->record($line, StatementLineMatchStatus::Matched, $id, 85, __(':n-day amount match.', ['n' => $tolerance]));
                $summary['matched']++;
                $resolved[$i] = true;
            } elseif (count($near) > 1) {
                // Suggest the nearest by date but do not claim — the user decides.
                $this->record($line, StatementLineMatchStatus::Suggested, $near[0]['id'], 60, __('Amount matches several nearby entries.'));
                $summary['suggested']++;
                $resolved[$i] = true;
            }
        }

        // Pass C — amount matches somewhere in the ledger but outside the date window.
        foreach ($active as $i => $line) {
            if (isset($resolved[$i])) {
                continue;
            }

            $any = $this->candidatesByAmount($available, (int) $line->amount_cents);

            if (count($any) >= 1) {
                $this->record($line, StatementLineMatchStatus::Suggested, $any[0]['id'], 50, __('Amount matches an entry on a different date.'));
                $summary['suggested']++;

                continue;
            }

            $this->record($line, StatementLineMatchStatus::Unmatched, null, null, __('No matching entry — add it.'));
            $summary['unmatched']++;
        }

        // Pre-fill a suggested category on the remaining unmatched lines: company
        // bank rules first, then history, then an AI guess (each only filling
        // lines still without a suggestion). The user confirms on review.
        $this->suggestions->fill($import);

        $this->persistCounts($import, $summary, $lines->count());

        return $summary;
    }

    /**
     * Uncleared, posted, non-voided journal lines on the bank account, grouped by
     * their signed book-delta for O(1) amount lookups.
     *
     * @return array<int, list<array{id: int, date: CarbonImmutable}>> keyed by signed cents
     */
    private function candidateBookLines(int $accountId): array
    {
        $rows = JournalLine::query()
            ->where('account_id', $accountId)
            ->where('is_posted', true)
            ->whereNull('cleared_at')
            ->whereNull('bank_reconciliation_id')
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true)->whereNull('voided_at'))
            ->get(['id', 'entry_date', 'debit_cents', 'credit_cents']);

        $byAmount = [];
        foreach ($rows as $row) {
            $signed = (int) $row->debit_cents - (int) $row->credit_cents;
            $byAmount[$signed][] = [
                'id' => (int) $row->id,
                'date' => CarbonImmutable::parse($row->entry_date),
            ];
        }

        return $byAmount;
    }

    /**
     * Fingerprints already committed for this account — the basis for skipping
     * re-uploaded transactions.
     *
     * @return array<string, true>
     */
    private function committedFingerprints(BankStatementImport $import): array
    {
        $fingerprints = BankStatementLine::query()
            ->where('account_id', $import->account_id)
            ->where('bank_statement_import_id', '!=', $import->id)
            ->whereHas('import', fn ($q) => $q->where('status', BankStatementImportStatus::Committed->value))
            ->pluck('fingerprint')
            ->all();

        return array_fill_keys($fingerprints, true);
    }

    /**
     * @param  array<int, list<array{id: int, date: CarbonImmutable}>>  $available
     */
    private function claimExact(array &$available, int $amount, CarbonImmutable $date): ?int
    {
        foreach ($available[$amount] ?? [] as $key => $candidate) {
            if ($candidate['date']->isSameDay($date)) {
                unset($available[$amount][$key]);

                return $candidate['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<int, list<array{id: int, date: CarbonImmutable}>>  $available
     * @return list<array{id: int, days: int}> candidates within tolerance, nearest first
     */
    private function candidatesByAmountWithinDays(array $available, int $amount, CarbonImmutable $date, int $tolerance): array
    {
        $matches = [];
        foreach ($available[$amount] ?? [] as $candidate) {
            $days = (int) abs($candidate['date']->diffInDays($date));
            if ($days <= $tolerance) {
                $matches[] = ['id' => $candidate['id'], 'days' => $days];
            }
        }

        usort($matches, fn ($a, $b) => $a['days'] <=> $b['days']);

        return $matches;
    }

    /**
     * @param  array<int, list<array{id: int, date: CarbonImmutable}>>  $available
     * @return list<array{id: int}>
     */
    private function candidatesByAmount(array $available, int $amount): array
    {
        return array_map(fn ($c) => ['id' => $c['id']], $available[$amount] ?? []);
    }

    /**
     * @param  array<int, list<array{id: int, date: CarbonImmutable}>>  $available
     */
    private function claim(array &$available, int $amount, int $candidateId): int
    {
        foreach ($available[$amount] ?? [] as $key => $candidate) {
            if ($candidate['id'] === $candidateId) {
                unset($available[$amount][$key]);
                break;
            }
        }

        return $candidateId;
    }

    private function record(BankStatementLine $line, StatementLineMatchStatus $status, ?int $journalLineId, ?int $confidence, string $reason): void
    {
        $line->forceFill([
            'match_status' => $status->value,
            'matched_journal_line_id' => $journalLineId,
            'match_confidence' => $confidence,
            'match_reason' => $reason,
        ])->save();
    }

    /**
     * @param  array{matched: int, suggested: int, unmatched: int, duplicate: int}  $summary
     */
    private function persistCounts(BankStatementImport $import, array $summary, int $lineCount): void
    {
        DB::transaction(function () use ($import, $summary, $lineCount) {
            $import->forceFill([
                'line_count' => $lineCount,
                'matched_count' => $summary['matched'],
                'duplicate_count' => $summary['duplicate'],
                'status' => BankStatementImportStatus::Ready->value,
            ])->save();
        });
    }
}
