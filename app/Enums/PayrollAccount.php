<?php

namespace App\Enums;

use App\Support\Defaults\CanadianDefaults;

/**
 * Logical payroll accounts the posting recipe resolves by code. Mirrors the
 * codes seeded in {@see CanadianDefaults::accounts()} and
 * back-filled onto existing companies by `payroll:backfill-accounts`.
 */
enum PayrollAccount: string
{
    // Expenses
    case WagesExpense = '6200';
    case EmployerCppExpense = '6210'; // Employer CPP/QPP (QPP reuses this account)
    case EmployerEiExpense = '6220';
    case EmployerQpipExpense = '6221';
    case VacationExpense = '6230';
    case QhsfExpense = '6240';
    case CnesstExpense = '6250';
    case EmployerBenefitsExpense = '6260'; // Employer-funded benefit/health contributions
    case EmployerRppExpense = '6270';      // Employer pension/RPP match
    case WorkersCompExpense = '6280';      // WSIB/WCB employer levy (rest of Canada)

    // Liabilities
    case CppPayable = '2400';
    case EiPayable = '2410';
    case IncomeTaxPayable = '2420';
    case QuebecIncomeTaxPayable = '2421';
    case QppPayable = '2422';
    case QpipPayable = '2423';
    case QhsfPayable = '2424';
    case CnesstPayable = '2425';
    case WorkersCompPayable = '2426'; // WSIB/WCB payable (rest of Canada)
    case VacationPayable = '2430';
    case BankedTimePayable = '2435'; // Banked-overtime liability (opt-in dollar mode)
    case NetPayClearing = '2440';
    case RrspPayable = '2450';
    case GarnishmentsPayable = '2460';
    case BenefitsPayable = '2470';

    public function code(): string
    {
        return $this->value;
    }

    public function accountName(): string
    {
        return match ($this) {
            self::WagesExpense => 'Wages & Salaries Expense',
            self::EmployerCppExpense => 'Employer CPP/QPP Expense',
            self::EmployerEiExpense => 'Employer EI Expense',
            self::EmployerQpipExpense => 'Employer QPIP Expense',
            self::VacationExpense => 'Vacation Pay Expense',
            self::QhsfExpense => 'QHSF Expense',
            self::CnesstExpense => 'CNESST Expense',
            self::EmployerBenefitsExpense => 'Employer Benefit Contributions Expense',
            self::EmployerRppExpense => 'Employer RPP Contributions Expense',
            self::WorkersCompExpense => 'Workers'."'".' Compensation Expense',
            self::CppPayable => 'CPP Payable',
            self::EiPayable => 'EI Payable',
            self::IncomeTaxPayable => 'Income Tax Payable',
            self::QuebecIncomeTaxPayable => 'Quebec Income Tax Payable',
            self::QppPayable => 'QPP Payable',
            self::QpipPayable => 'QPIP Payable',
            self::QhsfPayable => 'QHSF Payable',
            self::CnesstPayable => 'CNESST Payable',
            self::WorkersCompPayable => 'Workers'."'".' Compensation Payable',
            self::VacationPayable => 'Vacation Payable',
            self::BankedTimePayable => 'Banked Time Payable',
            self::NetPayClearing => 'Net Pay Clearing',
            self::RrspPayable => 'RRSP Payable',
            self::GarnishmentsPayable => 'Garnishments Payable',
            self::BenefitsPayable => 'Employee Benefits Payable',
        };
    }

    public function subtype(): AccountSubtype
    {
        return match ($this) {
            self::WagesExpense,
            self::EmployerCppExpense,
            self::EmployerEiExpense,
            self::EmployerQpipExpense,
            self::VacationExpense,
            self::QhsfExpense,
            self::CnesstExpense,
            self::EmployerBenefitsExpense,
            self::EmployerRppExpense,
            self::WorkersCompExpense => AccountSubtype::Expense,
            default => AccountSubtype::CurrentLiability,
        };
    }

    /**
     * System accounts the posting recipe resolves and that cannot be deleted.
     * The voluntary-deduction payables (RRSP, garnishments, benefits) are
     * user-selectable per deduction, so they are not system-locked.
     */
    public function isSystem(): bool
    {
        return ! in_array($this, [self::RrspPayable, self::GarnishmentsPayable, self::BenefitsPayable], true);
    }
}
