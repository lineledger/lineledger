<?php

namespace App\Services\Banking\Import\DTO;

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\Contracts\StatementParser;
use Carbon\CarbonImmutable;

/**
 * The cheap first pass over a file: enough to decide whether the user needs the
 * column-mapping wizard, to look up a saved profile by header signature, and to
 * show a preview. Returned by {@see StatementParser::sniff()}.
 */
final readonly class StatementProbe
{
    /**
     * @param  list<string>  $headers  original header labels (tabular only)
     * @param  list<array<string, ?string>>  $sampleRows  first few rows, header-keyed
     */
    public function __construct(
        public BankStatementFormat $format,
        public bool $needsMapping,
        public ?string $headerSignature = null,
        public array $headers = [],
        public array $sampleRows = [],
        public ?ColumnMapping $detectedMapping = null,
        public ?float $confidence = null,
        public ?CarbonImmutable $beginDate = null,
        public ?CarbonImmutable $endDate = null,
        public ?int $endBalanceCents = null,
        public ?string $rawText = null,
    ) {}
}
