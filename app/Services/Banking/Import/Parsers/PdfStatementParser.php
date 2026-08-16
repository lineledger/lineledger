<?php

namespace App\Services\Banking\Import\Parsers;

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\Contracts\StatementIntelligence;
use App\Services\Banking\Import\Contracts\StatementParser;
use App\Services\Banking\Import\DTO\ParsedStatement;
use App\Services\Banking\Import\DTO\ParsedTransaction;
use App\Services\Banking\Import\DTO\ParseOptions;
use App\Services\Banking\Import\DTO\StatementProbe;
use App\Services\Banking\Import\Support\PdfTextExtractor;
use App\Services\Banking\Import\Support\PdfTextStructurer;
use RuntimeException;
use Throwable;

/**
 * Parses statement PDFs in two tiers:
 *
 *  1. Local text extraction (poppler `pdftotext`, else smalot) → deterministic
 *     balance-delta structuring. Free and exact for normal and owner-password
 *     "protected" text PDFs.
 *  2. If that yields nothing — the PDF is secured beyond pdftotext, scanned with no
 *     text layer, or an unrecognised layout — and the optional AI layer is enabled,
 *     send the PDF itself to Claude, which reads the rendered document directly.
 *
 * Only when both tiers come up empty does it fail, with guidance toward CSV/OFX.
 */
final class PdfStatementParser implements StatementParser
{
    public function __construct(
        private readonly PdfTextExtractor $extractor,
        private readonly PdfTextStructurer $structurer,
        private readonly StatementIntelligence $intelligence,
    ) {}

    public function supports(BankStatementFormat $format): bool
    {
        return $format === BankStatementFormat::Pdf;
    }

    public function sniff(string $absolutePath, BankStatementFormat $format): StatementProbe
    {
        return new StatementProbe(
            format: $format,
            needsMapping: false,
            rawText: mb_substr($this->safeExtract($absolutePath), 0, 4000),
        );
    }

    public function parse(string $absolutePath, BankStatementFormat $format, ParseOptions $options): ParsedStatement
    {
        $text = $this->safeExtract($absolutePath);
        $hasUsableText = mb_strlen(trim($text)) >= 20;

        // Tier 1 — deterministic, free, exact when local text extraction worked.
        if ($hasUsableText) {
            $result = $this->structurer->structure($text);

            if ($result['transactions'] !== []) {
                return new ParsedStatement(
                    transactions: $result['transactions'],
                    beginDate: $result['beginDate'],
                    endDate: $result['endDate'],
                    endBalanceCents: $result['endBalanceCents'],
                    meta: ['parser' => 'pdf', 'rows_skipped' => $result['skipped'], 'ai_used' => false],
                );
            }
        }

        // Tier 2 — hand the PDF itself to Claude. Reads secured/scanned PDFs and
        // layouts the heuristic can't. A transient outage throws a "try again" message;
        // any other empty result falls through to the guidance below.
        if ($options->useAi && $this->intelligence->isEnabled()) {
            $aiTransactions = $this->intelligence->extractTransactionsFromPdf($absolutePath);

            if ($aiTransactions !== []) {
                return $this->fromTransactions($aiTransactions, aiUsed: true);
            }

            if ($this->intelligence->lastError() !== null) {
                throw new RuntimeException('AI extraction is temporarily unavailable. Please try again in a few minutes, or upload a CSV or OFX export instead.');
            }
        }

        if (! $hasUsableText) {
            throw new RuntimeException($options->useAi
                ? 'We could not read this PDF — it may be a scanned image or password-protected, and AI extraction could not read it either. Upload a CSV or OFX export instead.'
                : 'We could not read this PDF — it may be a scanned image or password-protected. Enable AI extraction, or upload a CSV or OFX export instead.');
        }

        throw new RuntimeException("We could not pick out transactions from this PDF's layout. Try a CSV or OFX export instead.");
    }

    /**
     * Extract the PDF's text layer, returning '' rather than throwing when the PDF is
     * secured/encrypted or otherwise unreadable — so the AI fallback can still take over.
     */
    private function safeExtract(string $absolutePath): string
    {
        try {
            return $this->extractor->extract($absolutePath);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Assemble a statement from an explicit transaction list (the AI path), deriving
     * the period and closing balance from the transactions themselves.
     *
     * @param  list<ParsedTransaction>  $transactions
     */
    private function fromTransactions(array $transactions, bool $aiUsed): ParsedStatement
    {
        $beginDate = null;
        $endDate = null;
        $endBalance = null;

        foreach ($transactions as $txn) {
            if ($beginDate === null || $txn->date->lessThan($beginDate)) {
                $beginDate = $txn->date;
            }
            if ($endDate === null || $txn->date->greaterThanOrEqualTo($endDate)) {
                $endDate = $txn->date;
                $endBalance = $txn->balanceCents ?? $endBalance;
            }
        }

        return new ParsedStatement(
            transactions: $transactions,
            beginDate: $beginDate,
            endDate: $endDate,
            endBalanceCents: $endBalance,
            meta: ['parser' => 'pdf', 'ai_used' => $aiUsed],
        );
    }
}
