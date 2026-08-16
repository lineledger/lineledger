<?php

namespace App\Services\Payroll\Verification;

use App\Services\Payroll\Data\EarningsBreakdown;
use App\Services\Payroll\Data\EmployeePayrollContext;
use App\Services\Payroll\PayrollDeductionEngine;
use App\Services\Proof\ProofValidator;
use Carbon\CarbonImmutable;

/**
 * Runs every {@see PayrollCheck} through the live {@see PayrollDeductionEngine}
 * and compares the result against the expected reference, to the exact cent.
 * Drives the verification test, the `payroll:verify-calculations` command, and
 * the in-app verification page — the payroll analog of {@see ProofValidator}.
 */
class PayrollVerificationRunner
{
    public function __construct(private PayrollDeductionEngine $engine) {}

    /**
     * @return array{
     *   summary: array{total: int, verified: int, awaiting: int, failed: int, passed: bool},
     *   checks: list<array<string, mixed>>
     * }
     */
    public function run(): array
    {
        $results = [];
        $verified = 0;
        $awaiting = 0;
        $failed = 0;
        $verifiedComponents = 0;
        $awaitingComponents = 0;

        foreach (PayrollVerificationDataset::checks() as $check) {
            $result = $this->evaluate($check);
            $results[] = $result;

            if (! $result['has_reference']) {
                $awaiting++;
            } elseif ($result['passed']) {
                $verified++;
            } else {
                $failed++;
            }

            foreach ($result['components'] as $component) {
                match ($component['status']) {
                    'match' => $verifiedComponents++,
                    'awaiting' => $awaitingComponents++,
                    default => null,
                };
            }
        }

        return [
            'summary' => [
                'total' => count($results),
                'verified' => $verified,
                'awaiting' => $awaiting,
                'failed' => $failed,
                'verified_components' => $verifiedComponents,
                'awaiting_components' => $awaitingComponents,
                'passed' => $failed === 0,
            ],
            'checks' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(PayrollCheck $check): array
    {
        $result = $this->engine->compute(
            new EmployeePayrollContext(
                province: $check->province,
                payPeriodsPerYear: $check->frequency->periodsPerYear(),
                payDate: CarbonImmutable::parse($check->payDate),
                federalClaimCents: $check->federalClaimCents,
                provincialClaimCents: $check->provincialClaimCents,
            ),
            new EarningsBreakdown(
                $check->grossPerPeriodCents,
                $check->grossPerPeriodCents,
                $check->grossPerPeriodCents,
                $check->grossPerPeriodCents,
            ),
            $check->ytd,
        );

        $components = [
            'cpp' => $this->component('CPP', $check->expectedCppEmployeeCents, $result->cppEmployeeCents),
            'cpp2' => $this->component('CPP2', $check->expectedCpp2EmployeeCents, $result->cpp2EmployeeCents),
            'ei' => $this->component('EI', $check->expectedEiEmployeeCents, $result->eiEmployeeCents),
            'federal_tax' => $this->component('Federal tax', $check->expectedFederalTaxCents, $result->federalTaxCents),
            'provincial_tax' => $this->component('Provincial tax', $check->expectedProvincialTaxCents, $result->provincialTaxCents),
            'qpp' => $this->component('QPP', $check->expectedQppEmployeeCents, $result->qppEmployeeCents),
            'qpip' => $this->component('QPIP', $check->expectedQpipEmployeeCents, $result->qpipEmployeeCents),
            'quebec_tax' => $this->component('Quebec tax', $check->expectedQuebecTaxCents, $result->quebecTaxCents),
        ];

        $hasReference = collect($components)->contains(fn ($c) => $c['expected'] !== null);
        $passed = collect($components)
            ->filter(fn ($c) => $c['expected'] !== null)
            ->every(fn ($c) => $c['status'] === 'match');

        return [
            'id' => $check->id,
            'label' => $check->label,
            'province' => $check->province,
            'frequency' => $check->frequency->label(),
            'gross_cents' => $check->grossPerPeriodCents,
            'source' => $check->source,
            'notes' => $check->notes,
            'components' => $components,
            'has_reference' => $hasReference,
            'passed' => $hasReference && $passed,
        ];
    }

    /**
     * @return array{label: string, expected: ?int, actual: int, status: string}
     */
    private function component(string $label, ?int $expected, int $actual): array
    {
        return [
            'label' => $label,
            'expected' => $expected,
            'actual' => $actual,
            'status' => $expected === null ? 'awaiting' : ($expected === $actual ? 'match' : 'mismatch'),
        ];
    }
}
