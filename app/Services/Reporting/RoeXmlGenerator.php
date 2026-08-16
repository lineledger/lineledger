<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use DOMDocument;
use DOMElement;

/**
 * Generates a Service Canada ROE Web bulk-import XML document for one Record of
 * Employment, from the {@see RoeCalculator} worksheet. A bulk envelope can carry
 * many ROEs; this emits one. Sibling of {@see T4XmlGenerator}.
 *
 * IMPORTANT — the Service Canada ROE Web import schema is revised periodically and
 * validation is strict. Block 4/5 (the employer payroll account) must match your
 * Service Canada registration, and the output should be validated against the
 * current ROE Web schema before bulk submission.
 *
 * Block map (ROE form):
 *   9   Employer name + address      11  Last day for which paid
 *   10  First day worked             12  Final pay period ending date
 *   14  Employee SIN                 15A Total insurable hours
 *   15B Total insurable earnings     15C Insurable earnings by pay period
 *   16  Reason for issuing           19  Employee name
 */
class RoeXmlGenerator
{
    /**
     * @param  array<string, mixed>  $roe  From {@see RoeCalculator::build()}
     */
    public function generate(Company $company, Contact $employee, array $roe): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $bulk = $doc->createElement('ROEWEB_BULK');
        $doc->appendChild($bulk);

        $bulk->appendChild($this->roe($doc, $company, $employee, $roe));

        return (string) $doc->saveXML();
    }

    /**
     * @param  array<string, mixed>  $roe
     */
    private function roe(DOMDocument $doc, Company $company, Contact $employee, array $roe): DOMElement
    {
        $profile = EmployeePayrollProfile::query()->withoutGlobalScopes()
            ->where('contact_id', $employee->id)->first();

        $record = $doc->createElement('ROE');

        // Block 4/5 — employer payroll account.
        $this->add($doc, $record, 'BLK4_PAYROLL_ACCT', $this->bn((string) ($company->payroll_business_number ?: $company->tax_number)));
        $this->add($doc, $record, 'BLK5_CRA_BN', $this->bn((string) $company->tax_number));

        // Block 9 — employer name + address.
        $employer = $doc->createElement('BLK9_EMPLOYER');
        $this->add($doc, $employer, 'name', $this->clean($company->name, 80));
        $this->add($doc, $employer, 'addr_l1', $this->clean((string) $company->address_line1, 60));
        $this->add($doc, $employer, 'city', $this->clean((string) $company->address_city, 30));
        $this->add($doc, $employer, 'prov_cd', (string) $company->address_region);
        $this->add($doc, $employer, 'pstl_cd', $this->postal((string) $company->address_postal_code));
        $record->appendChild($employer);

        // Block 14 — employee SIN, Block 19 — employee name.
        $this->add($doc, $record, 'BLK14_SIN', $this->sin($profile));

        $name = $doc->createElement('BLK19_EMPLOYEE');
        $this->add($doc, $name, 'snm', $this->clean((string) ($employee->last_name ?: $roe['employee']), 30));
        $this->add($doc, $name, 'gvn_nm', $this->clean((string) ($employee->first_name ?? ''), 30));
        $record->appendChild($name);

        // Blocks 10/11/12 — period dates.
        $this->add($doc, $record, 'BLK10_FIRST_DAY', (string) ($roe['first_day'] ?? ''));
        $this->add($doc, $record, 'BLK11_LAST_DAY_PAID', (string) ($roe['last_day'] ?? ''));
        $this->add($doc, $record, 'BLK12_FINAL_PERIOD_END', (string) ($roe['final_period_end'] ?? ''));

        // Block 16 — reason for issuing.
        $this->add($doc, $record, 'BLK16_REASON_CD', (string) $roe['reason']);

        // Block 15A/15B — totals.
        $this->add($doc, $record, 'BLK15A_TOTAL_INS_HOURS', $this->hours((string) $roe['total_insurable_hours']));
        $this->money($doc, $record, 'BLK15B_TOTAL_INS_EARNINGS', (int) $roe['total_insurable_earnings_cents']);

        // Block 15C — insurable earnings by pay period (most recent first, as filed).
        $byPeriod = $doc->createElement('BLK15C_PAY_PERIODS');
        $i = 1;
        foreach ($roe['periods'] as $period) {
            $pp = $doc->createElement('PP');
            $this->add($doc, $pp, 'pp_no', (string) $i++);
            $this->add($doc, $pp, 'period_end', (string) $period['period_end']);
            $this->add($doc, $pp, 'ins_hours', $this->hours((string) $period['insurable_hours']));
            $this->money($doc, $pp, 'ins_earnings', (int) $period['insurable_earnings_cents']);
            $byPeriod->appendChild($pp);
        }
        $record->appendChild($byPeriod);

        return $record;
    }

    private function add(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $element = $doc->createElement($name);
        $element->appendChild($doc->createTextNode($value)); // escapes &, <, > correctly
        $parent->appendChild($element);
    }

    private function money(DOMDocument $doc, DOMElement $parent, string $name, int $cents): void
    {
        $parent->appendChild($doc->createElement($name, number_format($cents / 100, 2, '.', '')));
    }

    private function hours(string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function sin(?EmployeePayrollProfile $profile): string
    {
        $sin = $profile?->sin_encrypted; // decrypted by the cast

        return $sin !== null && $sin !== '' ? (preg_replace('/\D/', '', $sin) ?? '000000000') : '000000000';
    }

    private function bn(string $taxNumber): string
    {
        $clean = preg_replace('/\s+/', '', $taxNumber) ?? '';

        return $clean !== '' ? $clean : '000000000RP0000';
    }

    private function clean(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }

    private function postal(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }
}
