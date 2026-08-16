<?php

namespace App\Actions\Payroll;

use App\Enums\RemittanceAgency;
use App\Enums\RemittanceFrequency;
use App\Enums\RemittanceStatus;
use App\Models\Company;
use App\Models\PayrollRemittance;
use App\Services\Posting\PayrollRemittancePoster;
use App\Services\Reporting\PayrollRemittanceCalculator;
use App\Services\Reporting\QuebecRemittanceCalculator;
use App\Services\Reporting\WorkersCompRemittanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a source-deduction remittance for an agency + period: snapshots the
 * amounts owed (from the matching calculator over the period's POSTED pay runs),
 * persists the {@see PayrollRemittance}, and posts the balanced journal entry that
 * clears the statutory payables against the bank.
 *
 * Guards a double-remit: a period that already has a Paid remittance for the
 * agency is rejected (a previously voided one may be re-recorded).
 *
 * Expected $data shape:
 *   agency:         string (RemittanceAgency value)
 *   period_start:   string (Y-m-d)
 *   period_end:     string (Y-m-d)
 *   due_date:       string (Y-m-d)
 *   bank_account_id: int
 *   payment_date:   string (Y-m-d)
 *   reference:      ?string
 *   notes:          ?string
 */
final class RecordPayrollRemittance
{
    public function __construct(
        private PayrollRemittanceCalculator $cra,
        private QuebecRemittanceCalculator $quebec,
        private WorkersCompRemittanceCalculator $workersComp,
        private PayrollRemittancePoster $poster,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): PayrollRemittance
    {
        return DB::transaction(function () use ($data): PayrollRemittance {
            /** @var Company $company */
            $company = app('current_company');

            $agency = RemittanceAgency::from($data['agency']);
            $start = CarbonImmutable::parse($data['period_start'])->startOfDay();
            $end = CarbonImmutable::parse($data['period_end'])->endOfDay();

            $alreadyPaid = PayrollRemittance::query()
                ->where('company_id', $company->id)
                ->where('agency', $agency->value)
                ->whereDate('period_start', $start->toDateString())
                ->where('status', RemittanceStatus::Paid->value)
                ->exists();

            if ($alreadyPaid) {
                throw new RuntimeException('This period has already been remitted to '.$agency->label().'.');
            }

            $summary = match ($agency) {
                RemittanceAgency::Cra => $this->cra->summary($company, $start, $end),
                RemittanceAgency::RevenuQuebec => $this->quebec->summary($company, $start, $end),
                RemittanceAgency::WorkersComp => $this->workersComp->summary($company, $start, $end),
            };

            $total = (int) $summary['remittance_due_cents'];

            if ($total <= 0) {
                throw new RuntimeException('There is nothing to remit for this period.');
            }

            $frequency = $company->payroll_remittance_frequency ?? RemittanceFrequency::Monthly;

            $remittance = PayrollRemittance::create([
                'agency' => $agency,
                'frequency' => $frequency,
                'period_start' => $start->toDateString(),
                'period_end' => CarbonImmutable::parse($data['period_end'])->toDateString(),
                'due_date' => CarbonImmutable::parse($data['due_date'])->toDateString(),
                'status' => RemittanceStatus::Paid,
                'total_cents' => $total,
                'breakdown' => $summary,
                'bank_account_id' => $data['bank_account_id'],
                'payment_date' => CarbonImmutable::parse($data['payment_date'])->toDateString(),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->poster->post($remittance);

            return $remittance->refresh();
        });
    }
}
