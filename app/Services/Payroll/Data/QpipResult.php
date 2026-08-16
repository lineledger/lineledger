<?php

namespace App\Services\Payroll\Data;

/**
 * Québec Parental Insurance Plan (QPIP / RQAP) premiums for one pay period (all
 * integer cents). Quebec only.
 */
final readonly class QpipResult
{
    public function __construct(
        public int $qpipEmployeeCents,
        public int $qpipEmployerCents,
        public int $insurableUsedCents,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0, 0);
    }
}
