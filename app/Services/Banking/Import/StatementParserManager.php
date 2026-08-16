<?php

namespace App\Services\Banking\Import;

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\Contracts\StatementParser;
use App\Services\Banking\Import\Parsers\OfxStatementParser;
use App\Services\Banking\Import\Parsers\PdfStatementParser;
use App\Services\Banking\Import\Parsers\TabularStatementParser;
use RuntimeException;

/**
 * Resolves the right {@see StatementParser} for an uploaded file's format.
 */
final class StatementParserManager
{
    /** @var list<StatementParser> */
    private array $parsers;

    public function __construct(TabularStatementParser $tabular, OfxStatementParser $ofx, PdfStatementParser $pdf)
    {
        $this->parsers = [$tabular, $ofx, $pdf];
    }

    public function for(BankStatementFormat $format): StatementParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($format)) {
                return $parser;
            }
        }

        throw new RuntimeException("No statement parser supports {$format->label()} files yet.");
    }
}
