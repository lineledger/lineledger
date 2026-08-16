<?php

namespace App\Services\Banking\Import\Parsers;

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\Contracts\StatementParser;
use App\Services\Banking\Import\DTO\ParsedStatement;
use App\Services\Banking\Import\DTO\ParsedTransaction;
use App\Services\Banking\Import\DTO\ParseOptions;
use App\Services\Banking\Import\DTO\StatementProbe;
use App\Services\Banking\Import\Support\AmountParser;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses the OFX family (OFX, QFX, QBO) with no external dependency. These formats
 * are structured and standardized, so there is never a column mapping: each
 * <STMTTRN> carries a signed <TRNAMT>, a <DTPOSTED>, a unique <FITID> (perfect
 * dedup), and a description. Field values are pulled with a tolerant regex that
 * works for both SGML (OFX 1.x, unclosed tags) and XML (OFX 2.x, closed tags).
 *
 * OFX TRNAMT is already signed the way we store amount_cents (deposit / card
 * payment positive; withdrawal / card charge negative), so no flipping is needed.
 */
final class OfxStatementParser implements StatementParser
{
    public function supports(BankStatementFormat $format): bool
    {
        return $format->isOfxFamily();
    }

    public function sniff(string $absolutePath, BankStatementFormat $format): StatementProbe
    {
        $statement = $this->parse($absolutePath, $format, new ParseOptions);

        return new StatementProbe(
            format: $format,
            needsMapping: false,
            beginDate: $statement->beginDate,
            endDate: $statement->endDate,
            endBalanceCents: $statement->endBalanceCents,
        );
    }

    public function parse(string $absolutePath, BankStatementFormat $format, ParseOptions $options): ParsedStatement
    {
        $content = (string) file_get_contents($absolutePath);

        // Isolate the transaction list so statement-level fields (e.g. LEDGERBAL)
        // can't bleed into the last transaction when SGML omits closing tags.
        $listRegion = $this->between($content, 'BANKTRANLIST') ?? $content;

        $chunks = preg_split('/<STMTTRN>/i', $listRegion) ?: [];
        array_shift($chunks); // drop the preamble before the first transaction

        $transactions = [];
        foreach ($chunks as $chunk) {
            $chunk = $this->untilTransactionEnd($chunk);

            $amount = AmountParser::toCents($this->field($chunk, 'TRNAMT'));
            $date = $this->date($this->field($chunk, 'DTPOSTED'));

            if ($amount === null || $date === null) {
                continue;
            }

            $transactions[] = new ParsedTransaction(
                date: $date,
                amountCents: $amount,
                description: $this->description($chunk),
                externalId: $this->field($chunk, 'FITID'),
                checkNumber: $this->field($chunk, 'CHECKNUM'),
                raw: ['ofx' => trim($chunk)],
            );
        }

        $ledger = $this->between($content, 'LEDGERBAL');
        $endBalance = $ledger !== null ? AmountParser::toCents($this->field($ledger, 'BALAMT')) : null;

        return new ParsedStatement(
            transactions: $transactions,
            beginDate: $this->date($this->field($content, 'DTSTART')),
            endDate: $this->date($this->field($content, 'DTEND'))
                ?? ($ledger !== null ? $this->date($this->field($ledger, 'DTASOF')) : null),
            endBalanceCents: $endBalance,
            currency: $this->field($content, 'CURDEF'),
            meta: ['parser' => 'ofx', 'format' => $format->value, 'ai_used' => false],
        );
    }

    /**
     * NAME plus MEMO, joined — OFX splits payee and detail across the two.
     */
    private function description(string $chunk): string
    {
        $parts = array_filter([
            $this->field($chunk, 'NAME'),
            $this->field($chunk, 'MEMO'),
        ]);

        return implode(' ', $parts);
    }

    /**
     * Read a single OFX field value. The character class stops at the next tag
     * (XML closing tag) or newline (SGML), so it handles both dialects.
     */
    private function field(string $haystack, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'>([^<\r\n]+)/i', $haystack, $m) === 1) {
            $value = trim($m[1]);

            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * The inner text of a container tag, tolerant of a missing closing tag.
     */
    private function between(string $content, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'>(.*?)<\/'.$tag.'>/is', $content, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function untilTransactionEnd(string $chunk): string
    {
        foreach (['</STMTTRN>', '<STMTTRN>', '</BANKTRANLIST>'] as $marker) {
            $pos = stripos($chunk, $marker);
            if ($pos !== false) {
                $chunk = substr($chunk, 0, $pos);
            }
        }

        return $chunk;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || strlen($value) < 8) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Ymd', substr($value, 0, 8)) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
