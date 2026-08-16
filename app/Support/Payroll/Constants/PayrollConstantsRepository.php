<?php

namespace App\Support\Payroll\Constants;

use App\Exceptions\Payroll\MissingPayrollConstantsException;
use Carbon\CarbonInterface;

/**
 * Resolves the federal + provincial payroll constants for a (province, pay date)
 * into a single {@see PayrollConstantSet}. Fails safe: an unloaded period or
 * province throws rather than falling back to stale tables. Quebec is a loaded
 * province like any other; its provincial block carries the extra QPP/QPIP/
 * Revenu-Québec data.
 */
class PayrollConstantsRepository
{
    public function resolve(string $province, CarbonInterface $payDate): PayrollConstantSet
    {
        $province = mb_strtoupper($province);
        $date = $payDate->format('Y-m-d');

        $federal = FederalConstants::for($date);

        if ($federal === null) {
            throw MissingPayrollConstantsException::forDate($date);
        }

        $provincial = ProvincialConstants::for($province, $date);

        if ($provincial === null) {
            throw MissingPayrollConstantsException::forProvince($province, $date);
        }

        return new PayrollConstantSet($date, $province, $federal, $provincial);
    }

    public function isSupportedProvince(string $province, CarbonInterface $payDate): bool
    {
        return ProvincialConstants::for(mb_strtoupper($province), $payDate->format('Y-m-d')) !== null;
    }

    /**
     * @return array<int, string>
     */
    public function loadedProvinces(): array
    {
        return ProvincialConstants::loadedProvinces();
    }
}
