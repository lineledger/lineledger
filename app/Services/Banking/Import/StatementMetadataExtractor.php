<?php

namespace App\Services\Banking\Import;

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\DTO\ParseOptions;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads just the header facts a reconciliation needs — the closing balance and
 * statement end date — from an uploaded statement, without running the full
 * import pipeline. Used to pre-fill (but not lock) the reconcile form when a
 * statement is dropped on it. Best-effort: anything it can't read comes back
 * null so the user simply types the numbers in.
 *
 * Limited to self-describing formats (PDF and the OFX family) that carry a
 * period and closing balance without a column mapping; tabular CSV/XLSX, which
 * need a mapping wizard, are skipped here.
 */
class StatementMetadataExtractor
{
    private const SELF_DESCRIBING = [
        BankStatementFormat::Pdf,
        BankStatementFormat::Ofx,
        BankStatementFormat::Qfx,
        BankStatementFormat::Qbo,
    ];

    public function __construct(private readonly StatementParserManager $parsers) {}

    /**
     * @return array{endBalanceCents: ?int, endDate: ?CarbonImmutable, beginDate: ?CarbonImmutable}
     */
    public function extract(string $absolutePath, BankStatementFormat $format): array
    {
        $blank = ['endBalanceCents' => null, 'endDate' => null, 'beginDate' => null];

        if (! in_array($format, self::SELF_DESCRIBING, true)) {
            return $blank;
        }

        try {
            $parsed = $this->parsers->for($format)->parse($absolutePath, $format, new ParseOptions(useAi: false));
        } catch (Throwable) {
            // Unreadable / image-only PDF, malformed OFX — fall back to manual entry.
            return $blank;
        }

        return [
            'endBalanceCents' => $parsed->endBalanceCents,
            'endDate' => $parsed->endDate,
            'beginDate' => $parsed->beginDate,
        ];
    }
}
