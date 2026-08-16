<?php

namespace App\Support\Reporting;

/**
 * One row of a Sales/Purchases comparison report: the current-period figures for
 * a dimension (customer, item, or rep) alongside the prior-period figures, with
 * the change derived on demand. A dedicated object (rather than an array shape)
 * keeps the report builder's Collection return type clean under static analysis.
 */
final readonly class ComparisonRow
{
    public function __construct(
        public ?int $key,
        public string $label,
        public float $qty,
        public int $amountCents,
        public float $priorQty,
        public int $priorAmountCents,
    ) {}

    public function changeCents(): int
    {
        return $this->amountCents - $this->priorAmountCents;
    }

    /**
     * Percentage change versus the prior period, or null when the prior amount is
     * zero (no meaningful base to compare against — rendered as an em dash).
     */
    public function changePct(): ?float
    {
        return $this->priorAmountCents !== 0
            ? ($this->amountCents - $this->priorAmountCents) / abs($this->priorAmountCents) * 100
            : null;
    }

    public function qtyChange(): float
    {
        return $this->qty - $this->priorQty;
    }
}
