<?php

namespace App\Services\Payroll\Data;

/**
 * EI premiums for one pay period (all integer cents).
 */
final readonly class EiResult
{
    public function __construct(
        public int $eiEmployeeCents,
        public int $eiEmployerCents,
        public int $insurableUsedCents,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0, 0);
    }
}
