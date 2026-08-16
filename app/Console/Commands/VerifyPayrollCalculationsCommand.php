<?php

namespace App\Console\Commands;

use App\Services\Payroll\Verification\PayrollVerificationRunner;
use Illuminate\Console\Command;

/**
 * Runs the payroll engine against its reference matrix and prints a pass/fail
 * table — the payroll analog of `proof:generate`. Exits non-zero on any
 * mismatch so it can gate a release. Cases marked "awaiting" have no reference
 * value loaded yet (run them through CRA PDOC and fill them in).
 */
class VerifyPayrollCalculationsCommand extends Command
{
    protected $signature = 'payroll:verify-calculations';

    protected $description = 'Verify the payroll engine against its CPP/EI/income-tax reference matrix (exact cent).';

    public function handle(PayrollVerificationRunner $runner): int
    {
        $report = $runner->run();

        foreach ($report['checks'] as $check) {
            $this->newLine();
            $this->line("<options=bold>{$check['label']}</> <fg=gray>[{$check['source']}]</>");

            $rows = [];
            foreach ($check['components'] as $component) {
                $expected = $component['expected'] === null ? '—' : number_format($component['expected'] / 100, 2);
                $actual = number_format($component['actual'] / 100, 2);
                $mark = match ($component['status']) {
                    'match' => '<fg=green>✓</>',
                    'mismatch' => '<fg=red>✗</>',
                    default => '<fg=yellow>·</>',
                };
                $rows[] = [$mark, $component['label'], $expected, $actual];
            }

            $this->table(['', 'Component', 'Expected', 'Engine'], $rows);
        }

        $s = $report['summary'];
        $this->newLine();
        $this->line("Cases: {$s['total']} — <fg=green>{$s['verified']} verified</>, <fg=red>{$s['failed']} failed</>.");
        $this->line("Reference values: <fg=green>{$s['verified_components']} matched</>, <fg=yellow>{$s['awaiting_components']} awaiting a reference</>.");

        if ($s['awaiting_components'] > 0) {
            $this->warn('Some cases have no reference value. Run them through CRA PDOC (https://www.canada.ca/en/revenue-agency/services/e-services/digital-services-businesses/payroll-deductions-online-calculator.html) and paste the figures into PayrollVerificationDataset.');
        }

        if (! $s['passed']) {
            $this->error('Payroll verification FAILED — the engine disagrees with a reference value.');

            return self::FAILURE;
        }

        $this->info('All loaded payroll references match the engine to the cent.');

        return self::SUCCESS;
    }
}
