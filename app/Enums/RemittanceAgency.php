<?php

namespace App\Enums;

/**
 * The agency a payroll remittance is paid to. Each maps to the statutory payable
 * accounts a remittance clears (the liabilities every pay run credits) and the
 * matching component keys from the remittance calculator's summary.
 */
enum RemittanceAgency: string
{
    case Cra = 'cra';
    case RevenuQuebec = 'revenu_quebec';
    case WorkersComp = 'workers_comp';

    public function label(): string
    {
        return match ($this) {
            self::Cra => __('Canada Revenue Agency (PD7A)'),
            self::RevenuQuebec => __('Revenu Québec'),
            self::WorkersComp => __("Workers' compensation (WSIB/WCB)"),
        };
    }

    /**
     * The payable accounts this agency's remittance clears, paired with the
     * calculator-summary key that supplies each amount. The poster DRs each.
     *
     * @return array<int, array{account: PayrollAccount, key: string, memo: string}>
     */
    public function payableLegs(): array
    {
        return match ($this) {
            self::Cra => [
                ['account' => PayrollAccount::CppPayable, 'key' => 'total_cpp_cents', 'memo' => 'CPP/QPP'],
                ['account' => PayrollAccount::EiPayable, 'key' => 'total_ei_cents', 'memo' => 'EI'],
                ['account' => PayrollAccount::IncomeTaxPayable, 'key' => 'tax_cents', 'memo' => 'Income tax'],
            ],
            self::RevenuQuebec => [
                ['account' => PayrollAccount::QppPayable, 'key' => 'total_qpp_cents', 'memo' => 'QPP'],
                ['account' => PayrollAccount::QpipPayable, 'key' => 'total_qpip_cents', 'memo' => 'QPIP'],
                ['account' => PayrollAccount::QuebecIncomeTaxPayable, 'key' => 'quebec_tax_cents', 'memo' => 'Quebec income tax'],
                ['account' => PayrollAccount::QhsfPayable, 'key' => 'qhsf_cents', 'memo' => 'QHSF'],
                ['account' => PayrollAccount::CnesstPayable, 'key' => 'cnesst_cents', 'memo' => 'CNESST'],
            ],
            self::WorkersComp => [
                ['account' => PayrollAccount::WorkersCompPayable, 'key' => 'wc_cents', 'memo' => "Workers' compensation"],
            ],
        };
    }
}
