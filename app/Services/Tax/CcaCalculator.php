<?php

namespace App\Services\Tax;

use App\Enums\CcaClass;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\CcaPool;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Computes the T2125 Area A capital cost allowance (CCA) schedule for a tax year.
 *
 * Per CCA class: opening UCC (carried in by the user via {@see CcaPool}) plus the
 * year's additions (assets placed in service that year, from the asset register)
 * gives the base. The half-year rule applies the rate to only half of the net
 * additions. CCA is the declining-balance claim; the remainder carries forward as
 * closing UCC.
 *
 * Dispositions are not auto-detected (proceeds aren't tracked); adjust the next
 * year's opening UCC to reflect them.
 */
class CcaCalculator
{
    /**
     * @return array{rows: list<array{class: string, label: string, rate: float, opening_cents: int, additions_cents: int, cca_cents: int, closing_cents: int}>, total_cca_cents: int}
     */
    public function schedule(Company $company, int $taxYear): array
    {
        $start = CarbonImmutable::create($taxYear, 1, 1);
        $end = CarbonImmutable::create($taxYear, 12, 31);

        $openingByClass = CcaPool::query()
            ->where('company_id', $company->id)
            ->where('tax_year', $taxYear)
            ->get()
            ->mapWithKeys(fn (CcaPool $p) => [$p->cca_class->value => (int) $p->opening_ucc_cents]);

        $additionsByClass = $this->additionsByClass($company, $start, $end);

        // Every class that has an opening balance or additions this year.
        $classes = collect(CcaClass::cases())
            ->filter(fn (CcaClass $c) => ($openingByClass[$c->value] ?? 0) !== 0 || ($additionsByClass[$c->value] ?? 0) !== 0);

        $rows = [];
        $totalCca = 0;

        foreach ($classes as $class) {
            $opening = (int) ($openingByClass[$class->value] ?? 0);
            $additions = (int) ($additionsByClass[$class->value] ?? 0);

            // Half-year rule: rate applies to base less half of net additions.
            $halfYear = (int) intdiv(max(0, $additions), 2);
            $base = $opening + $additions;
            $adjustedBase = $base - $halfYear;

            $cca = (int) round($adjustedBase * $class->rate());
            $cca = max(0, min($cca, $base)); // never claim more than the pool holds
            $closing = $base - $cca;

            $rows[] = [
                'class' => $class->value,
                'label' => $class->label(),
                'rate' => $class->rate(),
                'opening_cents' => $opening,
                'additions_cents' => $additions,
                'cca_cents' => $cca,
                'closing_cents' => $closing,
            ];

            $totalCca += $cca;
        }

        return ['rows' => $rows, 'total_cca_cents' => $totalCca];
    }

    /**
     * Sum of asset cost placed in service during the year, grouped by the CCA
     * class of the asset's category.
     *
     * @return array<string, int>
     */
    private function additionsByClass(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $categoryClasses = AssetCategory::query()
            ->where('company_id', $company->id)
            ->whereNotNull('cca_class')
            ->pluck('cca_class', 'id');

        if ($categoryClasses->isEmpty()) {
            return [];
        }

        $additions = [];

        Asset::query()
            ->where('company_id', $company->id)
            ->whereIn('asset_category_id', $categoryClasses->keys())
            ->whereBetween('in_service_date', [$start->toDateString(), $end->toDateString()])
            ->get(['asset_category_id', 'cost_cents'])
            ->each(function (Asset $asset) use (&$additions, $categoryClasses) {
                $class = $categoryClasses[$asset->asset_category_id];
                $class = $class instanceof CcaClass ? $class->value : (string) $class;
                $additions[$class] = ($additions[$class] ?? 0) + (int) $asset->cost_cents;
            });

        return $additions;
    }
}
