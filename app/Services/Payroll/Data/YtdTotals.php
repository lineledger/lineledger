<?php

namespace App\Services\Payroll\Data;

/**
 * Year-to-date amounts (before this pay period) that the engine needs to enforce
 * the annual CPP/EI maximums and the CPP2 band, plus the Quebec QPP/QPIP
 * maximums (QPP shares the pensionable accumulator with CPP; QPIP needs its own
 * insurable accumulator because its $98,000 cap differs from the EI MIE). All
 * integer cents.
 */
final readonly class YtdTotals
{
    public function __construct(
        public int $pensionableCents = 0,
        public int $insurableCents = 0,
        public int $cppEmployeeCents = 0,
        public int $cpp2EmployeeCents = 0,
        public int $eiEmployeeCents = 0,
        public int $qppEmployeeCents = 0,
        public int $qpp2EmployeeCents = 0,
        public int $qpipEmployeeCents = 0,
        public int $qpipInsurableCents = 0,
        // Bonus-method taxable income already paid this year — positions a
        // later bonus's annual-tax delta in the right bracket.
        public int $bonusTaxableCents = 0,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
