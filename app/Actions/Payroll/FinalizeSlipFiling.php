<?php

namespace App\Actions\Payroll;

use App\Enums\SlipType;
use App\Models\Company;
use App\Models\PayrollSlipFiling;
use App\Services\Pdf\Slips\SlipFieldMaps;
use App\Services\Pdf\Slips\SlipTemplateRegistry;
use App\Services\Reporting\Rl1SlipCalculator;
use App\Services\Reporting\T4ASlipCalculator;
use App\Services\Reporting\T4SlipCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finalizes a year's slips (T4 / RL-1 / T4A): runs the matching calculator one
 * last time over the posted year and freezes the result — the filing row holds
 * the summary, one line per recipient holds the slip's associative array.
 * From then on the report page and the employee portal read the snapshot, so
 * later pay runs in the same year cannot silently change an issued slip.
 * {@see UnlockSlipFiling} reverses this to allow amending.
 */
final class FinalizeSlipFiling
{
    public function __construct(
        private T4SlipCalculator $t4,
        private Rl1SlipCalculator $rl1,
        private T4ASlipCalculator $t4a,
    ) {}

    public function handle(Company $company, SlipType $type, int $year): PayrollSlipFiling
    {
        $existing = PayrollSlipFiling::query()
            ->where('company_id', $company->id)
            ->where('slip_type', $type->value)
            ->where('year', $year)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'year' => __(':type slips for :year are already finalized. Unlock them first to amend.', ['type' => $type->label(), 'year' => $year]),
            ]);
        }

        // T4ASlipCalculator::slipsForYear() covers Jan 1–Dec 31 of the year and
        // returns only the reportable (>= $500) slips — the ones a T4A must be
        // issued for — so all three calculators are uniform here.
        [$slips, $summary] = match ($type) {
            SlipType::T4 => [$this->t4->slipsForYear($company, $year), $this->t4->summary($company, $year)],
            SlipType::Rl1 => [$this->rl1->slipsForYear($company, $year), $this->rl1->summary($company, $year)],
            SlipType::T4a => [$this->t4a->slipsForYear($company, $year), $this->t4a->summary($company, $year)],
        };

        if ($slips === []) {
            throw ValidationException::withMessages([
                'year' => __('There are no :type slips to finalize for :year.', ['type' => $type->label(), 'year' => $year]),
            ]);
        }

        // Record which rendering the slips will get so the snapshot is honest
        // about what was (and will be) issued: the official CRA/RQ template
        // when one is installed for the year, the labelled facsimile otherwise.
        // The full SIN is deliberately NOT snapshotted — official renders
        // decrypt it from the payroll profile at render time.
        $summary['slip_template'] = [
            'official' => app(SlipTemplateRegistry::class)->installed($type->value, $year)
                && SlipFieldMaps::for($type->value, $year) !== null,
            'year' => $year,
        ];

        return DB::transaction(function () use ($company, $type, $year, $slips, $summary): PayrollSlipFiling {
            $filing = PayrollSlipFiling::create([
                'company_id' => $company->id,
                'slip_type' => $type->value,
                'year' => $year,
                'finalized_at' => now(),
                'finalized_by_user_id' => auth()->id(),
                'summary' => $summary,
            ]);

            foreach ($slips as $slip) {
                $filing->lines()->create([
                    'company_id' => $company->id,
                    'contact_id' => $slip['contact_id'],
                    'data' => $slip,
                ]);
            }

            return $filing;
        });
    }
}
