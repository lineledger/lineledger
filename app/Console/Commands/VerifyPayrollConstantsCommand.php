<?php

namespace App\Console\Commands;

use App\Support\Payroll\Constants\FederalConstants;
use App\Support\Payroll\Constants\ProvincialConstants;
use App\Support\Payroll\RoundingPolicy;
use Illuminate\Console\Command;

/**
 * Re-derives the maximum CPP, CPP2 and EI contributions from each loaded
 * federal table's rate × ceiling and asserts they match the published maxima,
 * then does the same for Quebec's QPP, QPP2, EI-Quebec and QPIP maxima. Run this
 * whenever the constants are updated (each January/July) to catch data-entry
 * errors before payroll is run.
 */
class VerifyPayrollConstantsCommand extends Command
{
    protected $signature = 'payroll:verify-constants';

    protected $description = 'Verify the loaded CRA payroll constants are internally consistent (derived maxima match published maxima).';

    public function handle(): int
    {
        $problems = 0;

        foreach (FederalConstants::loadedPeriods() as $period) {
            $c = FederalConstants::for($period);

            $derivedCpp = RoundingPolicy::centsTimesRate(
                $c['cpp']['max_pensionable_cents'] - $c['cpp']['basic_exemption_cents'],
                $c['cpp']['rate'],
            );
            $problems += $this->check($period, 'CPP max', $derivedCpp, $c['cpp']['max_contribution_cents']);

            $derivedCpp2 = RoundingPolicy::centsTimesRate(
                $c['cpp2']['upper_cents'] - $c['cpp2']['lower_cents'],
                $c['cpp2']['rate'],
            );
            $problems += $this->check($period, 'CPP2 max', $derivedCpp2, $c['cpp2']['max_contribution_cents']);

            $derivedEi = RoundingPolicy::centsTimesRate($c['ei']['max_insurable_cents'], $c['ei']['rate']);
            $problems += $this->check($period, 'EI max', $derivedEi, $c['ei']['max_premium_cents']);

            // Quebec (QPP / QPP2 / EI-Quebec / QPIP), when a QC table is loaded for the period.
            $qc = ProvincialConstants::for('QC', $period)['quebec'] ?? null;

            if ($qc !== null) {
                $derivedQpp = RoundingPolicy::centsTimesRate(
                    $qc['qpp']['max_pensionable_cents'] - $qc['qpp']['basic_exemption_cents'],
                    $qc['qpp']['rate'],
                );
                $problems += $this->check($period, 'QPP max', $derivedQpp, $qc['qpp']['max_contribution_cents']);

                $derivedQpp2 = RoundingPolicy::centsTimesRate(
                    $qc['qpp2']['upper_cents'] - $qc['qpp2']['lower_cents'],
                    $qc['qpp2']['rate'],
                );
                $problems += $this->check($period, 'QPP2 max', $derivedQpp2, $qc['qpp2']['max_contribution_cents']);

                // EI-Quebec uses the federal maximum insurable earnings at the reduced Quebec rate.
                $derivedEiQc = RoundingPolicy::centsTimesRate($c['ei']['max_insurable_cents'], $qc['ei']['rate']);
                $problems += $this->check($period, 'EI-Quebec max', $derivedEiQc, $qc['ei']['max_premium_cents']);

                $derivedQpip = RoundingPolicy::centsTimesRate($qc['qpip']['max_insurable_cents'], $qc['qpip']['employee_rate']);
                $problems += $this->check($period, 'QPIP max', $derivedQpip, $qc['qpip']['max_employee_premium_cents']);
            }
        }

        if ($problems > 0) {
            $this->error("{$problems} constant(s) failed verification.");

            return self::FAILURE;
        }

        $this->info('All loaded payroll constants are internally consistent.');

        return self::SUCCESS;
    }

    private function check(string $period, string $label, int $derived, int $published): int
    {
        if ($derived === $published) {
            $this->line(sprintf('  ✓ %s %s: $%s', $period, $label, number_format($published / 100, 2)));

            return 0;
        }

        $this->warn(sprintf('  ✗ %s %s: derived $%s ≠ published $%s', $period, $label, number_format($derived / 100, 2), number_format($published / 100, 2)));

        return 1;
    }
}
