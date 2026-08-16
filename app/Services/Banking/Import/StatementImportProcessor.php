<?php

namespace App\Services\Banking\Import;

use App\Enums\BankStatementImportStatus;
use App\Enums\StatementLineMatchStatus;
use App\Models\BankImportProfile;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Services\Banking\Import\Contracts\StatementIntelligence;
use App\Services\Banking\Import\DTO\ColumnMapping;
use App\Services\Banking\Import\DTO\ParsedStatement;
use App\Services\Banking\Import\DTO\ParseOptions;
use App\Services\Banking\Import\Support\StatementFingerprint;
use App\Support\Storage\TemporaryLocalFile;
use RuntimeException;
use Throwable;

/**
 * Drives one import from its uploaded file through parse → persist → match. Callers
 * must have `current_company` bound (the queued job binds it; the wizard runs inside
 * a request). For tabular formats it resolves a column mapping in priority order:
 * an explicit mapping (the wizard) → a saved profile (by header signature) → the
 * deterministic detector → otherwise it pauses at NeedsMapping for the user.
 */
class StatementImportProcessor
{
    public function __construct(
        private readonly StatementParserManager $parsers,
        private readonly StatementMatcher $matcher,
        private readonly StatementIntelligence $intelligence,
    ) {}

    public function process(BankStatementImport $import, ?ColumnMapping $mapping = null): void
    {
        $import->forceFill(['status' => BankStatementImportStatus::Parsing->value, 'error_message' => null])->save();

        $attachment = $import->attachment()->first();

        if ($attachment === null) {
            throw new RuntimeException("Statement import #{$import->id} has no uploaded file.");
        }

        // The parsers take a filesystem path — `pdftotext` is a separate process
        // and the tabular/OFX readers `fopen()` — so on object storage the blob
        // is streamed to scratch space for the duration of the parse.
        TemporaryLocalFile::with(
            $attachment->disk,
            $attachment->path,
            fn (string $path) => $this->parseAndMatch($import, $path, $mapping),
        );
    }

    /**
     * The parse → persist → match body, given a readable local path. Split out
     * so the temporary copy's lifetime brackets every use of `$path`.
     */
    private function parseAndMatch(BankStatementImport $import, string $path, ?ColumnMapping $mapping): void
    {
        $format = $import->source_format;
        $parser = $this->parsers->for($format);

        if ($format->needsColumnMapping() && $mapping === null) {
            $probe = $parser->sniff($path, $format);

            $mapping = $this->profileMapping($import, $probe->headerSignature)
                ?? $probe->detectedMapping;

            // Last resort before asking the user: let the optional AI layer infer the
            // mapping from a sample. The full file is still parsed deterministically.
            if ($mapping === null && $this->intelligence->isEnabled()) {
                $mapping = $this->intelligence->inferMapping($probe->headers, $probe->sampleRows);
            }

            if ($mapping === null) {
                $import->forceFill([
                    'status' => BankStatementImportStatus::NeedsMapping->value,
                    'parse_meta' => [
                        'headers' => $probe->headers,
                        'sample_rows' => $probe->sampleRows,
                        'header_signature' => $probe->headerSignature,
                        // True only when the AI layer was meant to help but the service
                        // was unreachable — lets the wizard say so instead of failing silently.
                        'ai_unavailable' => $this->intelligence->isEnabled() && $this->intelligence->lastError() !== null,
                    ],
                ])->save();

                return;
            }
        }

        try {
            $homeCurrency = $import->account?->company?->currency_code ?? 'CAD';
            $statement = $parser->parse($path, $format, new ParseOptions(
                mapping: $mapping,
                useAi: $this->intelligence->isEnabled(),
                homeCurrency: $homeCurrency,
            ));
        } catch (Throwable $e) {
            $import->forceFill([
                'status' => BankStatementImportStatus::Failed->value,
                'error_message' => $e->getMessage(),
            ])->save();

            return;
        }

        $this->persistLines($import, $statement);

        $import->forceFill([
            'status' => BankStatementImportStatus::Matching->value,
            'mapping' => $mapping?->toArray(),
            'statement_begin_date' => $statement->beginDate?->toDateString(),
            'statement_end_date' => $statement->endDate?->toDateString(),
            'statement_end_balance_cents' => $statement->endBalanceCents,
            'parse_meta' => $statement->meta,
        ])->save();

        $this->matcher->match($import); // advances to Ready and writes the counts
    }

    /**
     * Replace any previously parsed lines (a re-parse with a corrected mapping) and
     * persist the normalized transactions, fingerprinted for dedup.
     */
    private function persistLines(BankStatementImport $import, ParsedStatement $statement): void
    {
        $import->lines()->delete();

        $accountId = (int) $import->account_id;

        foreach ($statement->transactions as $txn) {
            $fingerprint = StatementFingerprint::for(
                $accountId,
                $txn->date->toDateString(),
                $txn->amountCents,
                $txn->description,
                $txn->externalId,
            );

            BankStatementLine::query()->create([
                'bank_statement_import_id' => $import->id,
                'account_id' => $accountId,
                'txn_date' => $txn->date->toDateString(),
                'amount_cents' => $txn->amountCents,
                'description' => $txn->description,
                'check_number' => $txn->checkNumber,
                'external_id' => $txn->externalId,
                'fingerprint' => $fingerprint,
                'balance_cents' => $txn->balanceCents,
                'raw' => $txn->raw,
                'match_status' => StatementLineMatchStatus::Unmatched->value,
            ]);
        }
    }

    private function profileMapping(BankStatementImport $import, ?string $headerSignature): ?ColumnMapping
    {
        if ($headerSignature === null) {
            return null;
        }

        $profile = BankImportProfile::query()
            ->where('header_signature', $headerSignature)
            ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $import->account_id))
            ->latest('last_used_at')
            ->latest('id')
            ->first();

        if ($profile === null) {
            return null;
        }

        $profile->markUsed();
        $import->forceFill(['bank_import_profile_id' => $profile->id])->save();

        return ColumnMapping::fromArray($profile->mapping);
    }
}
