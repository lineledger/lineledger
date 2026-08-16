<?php

namespace App\Services\Banking\Import\DTO;

/**
 * Inputs to a full parse: the confirmed column mapping (tabular), whether the
 * optional AI layer may be used, and the company's home currency for amount scaling.
 */
final readonly class ParseOptions
{
    public function __construct(
        public ?ColumnMapping $mapping = null,
        public bool $useAi = false,
        public string $homeCurrency = 'CAD',
    ) {}
}
