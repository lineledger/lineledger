<?php

namespace App\Services\Banking\Import\Contracts;

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\DTO\ParsedStatement;
use App\Services\Banking\Import\DTO\ParseOptions;
use App\Services\Banking\Import\DTO\StatementProbe;
use App\Services\Banking\Import\StatementParserManager;

/**
 * Turns a statement file of one format family into normalized transactions.
 * One implementation per family (tabular CSV/Excel, OFX, PDF). Resolved by
 * {@see StatementParserManager}.
 */
interface StatementParser
{
    public function supports(BankStatementFormat $format): bool;

    /**
     * Cheap first pass: detect a mapping, compute a header signature, grab a
     * preview. Does not parse the whole file.
     */
    public function sniff(string $absolutePath, BankStatementFormat $format): StatementProbe;

    /**
     * Full parse into normalized transactions. For tabular formats the caller
     * must supply a confirmed (or detected) mapping via {@see ParseOptions}.
     */
    public function parse(string $absolutePath, BankStatementFormat $format, ParseOptions $options): ParsedStatement;
}
