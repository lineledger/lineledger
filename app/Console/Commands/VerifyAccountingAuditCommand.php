<?php

namespace App\Console\Commands;

use App\Models\AccountingAuditLog;
use App\Models\AuditChainCheckpoint;
use App\Notifications\LedgerIntegrityAlert;
use App\Services\Audit\AccountingAuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class VerifyAccountingAuditCommand extends Command
{
    protected $signature = 'audit:verify
        {company? : Company ID; verifies all companies when omitted}
        {--full : Ignore any saved checkpoint and re-verify from genesis}
        {--no-alert : Report failures without logging or emailing}';

    protected $description = 'Verify the integrity of the accounting audit log hash chain.';

    /**
     * Rows fetched per keyset page. Small enough that peak memory stays flat on
     * a chain of any length, large enough that a big company isn't thousands of
     * round trips.
     */
    private const CHUNK_SIZE = 1000;

    public function handle(): int
    {
        $companyArg = $this->argument('company');

        $companyIds = $companyArg !== null
            ? [(int) $companyArg]
            : AccountingAuditLog::query()
                ->withoutGlobalScopes()
                ->distinct()
                ->orderBy('company_id')
                ->pluck('company_id')
                ->all();

        if ($companyIds === []) {
            $this->info('No audit rows found.');

            return self::SUCCESS;
        }

        $issuesByCompany = [];

        $full = (bool) $this->option('full');

        foreach ($companyIds as $companyId) {
            $result = $this->verifyCompany((int) $companyId, $full);

            $this->line(sprintf(
                'Company %d — checked %d row(s)%s, %d issue(s).',
                $companyId,
                $result['checked'],
                $result['resumed_from'] !== null ? ' after sequence '.$result['resumed_from'] : ' from genesis',
                count($result['issues']),
            ));

            foreach ($result['issues'] as $issue) {
                $this->error('  - '.$issue);
            }

            if ($result['issues'] !== []) {
                $issuesByCompany[$companyId] = $result['issues'];
            }
        }

        if ($issuesByCompany === []) {
            $this->info('Audit chain intact.');

            return self::SUCCESS;
        }

        // Alert on a standalone failure. Suppressed with --no-alert so the two
        // in-app callers that wrap this command don't double-notify: integrity:check
        // owns the nightly email, and the reports audit-log page verifies on demand.
        if (! $this->option('no-alert')) {
            Log::error('Audit chain verification failed.', $issuesByCompany);
            $this->sendAlert($issuesByCompany);
        }

        $this->error('Audit chain integrity check FAILED.');

        return self::FAILURE;
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

    /**
     * Walk a company's chain, resuming from its checkpoint unless $full.
     *
     * Rows are streamed in keyset-paginated chunks rather than loaded at once:
     * a chain is unbounded (nothing prunes it — the table's DELETE trigger sees
     * to that), and every row carries a payload plus its canonical hash_input,
     * so a single ->get() on a long-lived company is a multi-gigabyte hydration
     * inside the nightly integrity:check. Paginating on `sequence` — unique per
     * company, monotonic, and index-backed by the (company_id, sequence) unique
     * key — keeps the walk in the exact order being verified.
     *
     * Resuming makes the nightly cost O(rows written today) instead of O(all
     * history). The trade is that already-verified rows aren't re-examined,
     * which {@see CheckLedgerIntegrity::shouldFullyVerify()} covers by forcing
     * a genesis walk for a rotating shard of companies each night.
     *
     * @return array{checked: int, issues: list<string>, resumed_from: int|null}
     */
    protected function verifyCompany(int $companyId, bool $full = false): array
    {
        $checkpoint = $full ? null : $this->resumePoint($companyId);

        $query = AccountingAuditLog::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId);

        if ($checkpoint !== null) {
            $query->where('sequence', '>', $checkpoint->last_verified_sequence);
        }

        $rows = $query->lazyById(self::CHUNK_SIZE, 'sequence');

        $issues = [];
        $checked = 0;
        $expectedSequence = $checkpoint !== null ? $checkpoint->last_verified_sequence + 1 : 1;
        $expectedPrevHash = $checkpoint !== null
            ? $checkpoint->last_verified_row_hash
            : AccountingAuditRecorder::GENESIS_HASH;
        $tipSequence = $checkpoint?->last_verified_sequence;
        $tipRowHash = $checkpoint?->last_verified_row_hash;

        foreach ($rows as $row) {
            $checked++;

            $sequence = (int) $row->sequence;

            if ($sequence !== $expectedSequence) {
                $issues[] = "Sequence gap or duplicate at id={$row->id}: expected {$expectedSequence}, got {$sequence}.";
                $expectedSequence = $sequence;
            }

            if ($row->previous_hash !== $expectedPrevHash) {
                $issues[] = "Broken chain at sequence {$sequence} (id={$row->id}): previous_hash does not match prior row's row_hash.";
            }

            $computed = AccountingAuditRecorder::hashFromInput($row->previous_hash, $row->hash_input);

            if (! hash_equals($row->row_hash, $computed)) {
                $issues[] = "Hash mismatch at sequence {$sequence} (id={$row->id}): row contents do not match stored row_hash.";
            }

            // Cross-check that the queryable columns still match the canonical
            // hashed snapshot — catches tampering of payload, action,
            // recorded_at, etc. without an accompanying update to hash_input.
            $canonical = json_decode($row->hash_input, true);
            if (is_array($canonical)) {
                foreach ([
                    'company_id' => (int) $row->company_id,
                    'sequence' => $sequence,
                    'action' => $row->action->value,
                    'auditable_type' => $row->auditable_type,
                    'auditable_id' => (int) $row->auditable_id,
                ] as $key => $expected) {
                    if (($canonical[$key] ?? null) !== $expected) {
                        $issues[] = "Column mismatch at sequence {$sequence} (id={$row->id}): {$key} drifted from canonical hash input.";
                    }
                }

                if (($canonical['payload'] ?? null) != $row->payload) {
                    $issues[] = "Payload mismatch at sequence {$sequence} (id={$row->id}): payload column drifted from canonical hash input.";
                }
            }

            $expectedPrevHash = $row->row_hash;
            $expectedSequence++;
            $tipSequence = $sequence;
            $tipRowHash = $row->row_hash;
        }

        // Only ever checkpoint a clean walk. Advancing the watermark past a
        // reported break would seal it behind the resume point and no future
        // incremental run would look at it again.
        if ($issues === [] && $tipSequence !== null && $tipRowHash !== null) {
            $this->saveCheckpoint($companyId, $tipSequence, $tipRowHash);
        }

        return [
            'checked' => $checked,
            'issues' => $issues,
            'resumed_from' => $checkpoint?->last_verified_sequence,
        ];
    }

    /**
     * The saved watermark for a company, or null to walk from genesis.
     *
     * A checkpoint is believed only when the row it names still hashes to what
     * was recorded. That guards the obvious case — a forged or rolled-back
     * watermark used to hide rows from verification — and one routine one: a
     * company restored from backup is sitting on a different chain, so its old
     * watermark describes rows that no longer exist.
     */
    protected function resumePoint(int $companyId): ?AuditChainCheckpoint
    {
        $checkpoint = AuditChainCheckpoint::query()
            ->where('company_id', $companyId)
            ->first();

        if ($checkpoint === null) {
            return null;
        }

        $stillMatches = AccountingAuditLog::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('sequence', $checkpoint->last_verified_sequence)
            ->where('row_hash', $checkpoint->last_verified_row_hash)
            ->exists();

        if (! $stillMatches) {
            $this->warn(sprintf(
                'Company %d — checkpoint at sequence %d no longer matches the chain; re-verifying from genesis.',
                $companyId,
                $checkpoint->last_verified_sequence,
            ));

            return null;
        }

        return $checkpoint;
    }

    protected function saveCheckpoint(int $companyId, int $sequence, string $rowHash): void
    {
        AuditChainCheckpoint::query()->updateOrCreate(
            ['company_id' => $companyId],
            [
                'last_verified_sequence' => $sequence,
                'last_verified_row_hash' => $rowHash,
                'verified_at' => now(),
            ],
        );
    }
}
