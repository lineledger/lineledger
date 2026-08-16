<?php

namespace App\Concerns;

use Livewire\Attributes\Url;

/**
 * Accrual vs cash reporting basis for reports that support both. Cash-basis
 * figures come from CashBasisCalculator (recognition at payment application);
 * accrual is the default GL-driven path. The property is memorizable (see
 * ReportSettings::KEYS).
 */
trait HasReportBasis
{
    #[Url(as: 'basis')]
    public string $reportBasis = 'accrual';

    public function isCashBasis(): bool
    {
        return $this->reportBasis === 'cash';
    }

    public function basisLabel(): string
    {
        return $this->isCashBasis() ? __('Cash basis') : __('Accrual basis');
    }

    /**
     * @return array<string, string>
     */
    public static function basisOptions(): array
    {
        return [
            'accrual' => __('Accrual'),
            'cash' => __('Cash'),
        ];
    }

    public function updatedReportBasis(): void
    {
        if (! in_array($this->reportBasis, ['accrual', 'cash'], true)) {
            $this->reportBasis = 'accrual';
        }
    }
}
