<?php

namespace App\Enums;

use App\Models\Company;
use App\Support\Jurisdiction\JurisdictionProfile;

/**
 * The jurisdiction-locked capabilities a company may or may not use. Each case is
 * a feature whose availability depends on the company's jurisdiction — and, for
 * several, additionally on its organization type, legal structure, or feature
 * flags. Resolution lives in {@see JurisdictionProfile},
 * the single source of truth that every jurisdiction guard reads from via
 * {@see Company::supports()}.
 *
 * The backing string is a stable identity for tests/serialization only.
 *
 * Capabilities are deliberately NOT a flat country => list: many compose country
 * with entity/flags (e.g. T5013 needs a Canadian partnership). Terminology that
 * merely differs by country (GST/HST vs Sales Tax, cheque vs check) is NOT a
 * capability — that lives on {@see Country}.
 */
enum JurisdictionCapability: string
{
    // Canadian payroll (CPP/EI/T4127). Requires features_payroll && Canada.
    case Payroll = 'payroll';
    case T4Slips = 't4_slips';
    case T4ASlips = 't4a_slips';
    case Pd7aRemittance = 'pd7a_remittance';
    case RecordOfEmployment = 'roe';
    case WorkersComp = 'workers_comp';

    // CRA returns / GIFI — compose Canada with organization type & legal structure.
    case GifiStatement = 'gifi_statement';            // T2 (corporation / non-profit corporation)
    case T5013 = 't5013';                             // partnership
    case T2125 = 't2125';                             // sole proprietorship (+ CCA)
    case T3010 = 't3010';                             // registered charity
    case T1044 = 't1044';                             // NPO / club information return
    case GifiCodeMapping = 'gifi_code_mapping';       // per-account GIFI line mapping

    // United States contractor reporting.
    case Form1099 = 'form_1099';
    case Vendor1099Tracking = 'vendor_1099_tracking';

    // Pure-country Canadian features.
    case VendorT4ATracking = 'vendor_t4a_tracking';
    case CraTaxFiling = 'cra_tax_filing';             // Tax & filing settings + CRA-return framing
    case CharityDonationReceipts = 'charity_donation_receipts';
    case CanadianCapitalCostAllowance = 'canadian_cca';
}
