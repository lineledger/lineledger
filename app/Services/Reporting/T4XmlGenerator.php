<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Generates a CRA T4 electronic-filing XML document (Internet File Transfer
 * layout) for a tax year: a T619 transmitter record, one T4Slip per employee,
 * and a T4Summary. Amounts are emitted in dollars (2 decimals) as the CRA
 * schema expects.
 *
 * IMPORTANT — the CRA T4 XML schema is revised yearly and validation is strict.
 * The transmitter number (config payroll.transmitter.number) must be your
 * CRA-assigned "MM" account, and the output should be validated against the
 * current CRA schema before submission.
 */
class T4XmlGenerator
{
    /**
     * @param  array<int, array<string, mixed>>  $slips  From {@see T4SlipCalculator::slipsForYear()}
     * @param  array<string, int>  $summary  From {@see T4SlipCalculator::summary()}
     */
    public function generate(Company $company, int $year, array $slips, array $summary): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $submission = $doc->createElement('Submission');
        $doc->appendChild($submission);

        $submission->appendChild($this->transmitter($doc, $company, count($slips)));

        $return = $doc->createElement('Return');
        $submission->appendChild($return);

        $t4 = $doc->createElement('T4');
        $return->appendChild($t4);

        foreach ($slips as $slip) {
            $t4->appendChild($this->slip($doc, $company, $slip));
        }

        $t4->appendChild($this->summary($doc, $company, $year, $summary, count($slips)));

        return (string) $doc->saveXML();
    }

    private function transmitter(DOMDocument $doc, Company $company, int $slipCount): DOMElement
    {
        $t619 = $doc->createElement('T619');
        $this->add($doc, $t619, 'sbmt_ref_id', mb_substr($company->slug, 0, 8));
        $this->add($doc, $t619, 'rpt_tcd', 'O'); // Original
        $this->add($doc, $t619, 'trnmtr_nbr', (string) config('payroll.transmitter.number'));
        $this->add($doc, $t619, 'trnmtr_tcd', (string) config('payroll.transmitter.type'));
        $this->add($doc, $t619, 'summ_cnt', '1');
        $this->add($doc, $t619, 'lang_cd', (string) config('payroll.transmitter.language'));

        $name = $doc->createElement('TRNMTR_NM');
        $this->add($doc, $name, 'l1_nm', $this->clean($company->name, 30));
        $t619->appendChild($name);

        $addr = $doc->createElement('TRNMTR_ADDR');
        $this->add($doc, $addr, 'addr_l1_txt', $this->clean((string) $company->address_line1, 30));
        $this->add($doc, $addr, 'cty_nm', $this->clean((string) $company->address_city, 28));
        $this->add($doc, $addr, 'prov_cd', (string) $company->address_region);
        $this->add($doc, $addr, 'cntry_cd', 'CAN');
        $this->add($doc, $addr, 'pstl_cd', $this->postal((string) $company->address_postal_code));
        $t619->appendChild($addr);

        return $t619;
    }

    /**
     * @param  array<string, mixed>  $slip
     */
    private function slip(DOMDocument $doc, Company $company, array $slip): DOMElement
    {
        $contact = Contact::query()->withoutGlobalScopes()->find($slip['contact_id']);
        $profile = EmployeePayrollProfile::query()->withoutGlobalScopes()
            ->where('contact_id', $slip['contact_id'])->first();

        $t4Slip = $doc->createElement('T4Slip');

        $name = $doc->createElement('EMPE_NM');
        $this->add($doc, $name, 'snm', $this->clean((string) ($contact?->last_name ?: $slip['name']), 20));
        $this->add($doc, $name, 'gvn_nm', $this->clean((string) ($contact?->first_name ?? ''), 12));
        $t4Slip->appendChild($name);

        $this->add($doc, $t4Slip, 'sin', $this->sin($profile));
        $this->add($doc, $t4Slip, 'bn', $this->bn((string) $company->tax_number));
        $this->add($doc, $t4Slip, 'cpp_qpp_xmpt_cd', $profile?->cpp_exempt ? '1' : '0');
        $this->add($doc, $t4Slip, 'ei_xmpt_cd', $profile?->ei_exempt ? '1' : '0');
        $this->add($doc, $t4Slip, 'empt_prov_cd', (string) ($slip['province'] ?? $company->address_region));
        $this->add($doc, $t4Slip, 'rpt_tcd', 'O');

        $amt = $doc->createElement('T4_AMT');
        $this->money($doc, $amt, 'empt_incamt', $slip['box14']);      // Box 14
        $this->money($doc, $amt, 'cpp_cntrb_amt', $slip['box16']);    // Box 16
        $this->money($doc, $amt, 'cpp2_cntrb_amt', $slip['box16a']);  // Box 16A
        $this->money($doc, $amt, 'qpp_cntrb_amt', $slip['box17']);    // Box 17 (Quebec QPP)
        $this->money($doc, $amt, 'qpp2_cntrb_amt', $slip['box17a'] ?? 0); // Box 17A (second QPP)
        $this->money($doc, $amt, 'empe_eip_amt', $slip['box18']);     // Box 18
        $this->money($doc, $amt, 'itx_ddct_amt', $slip['box22']);     // Box 22
        $this->money($doc, $amt, 'ei_insur_ern_amt', $slip['box24']); // Box 24
        $this->money($doc, $amt, 'cpp_qpp_ern_amt', $slip['box26']);  // Box 26
        $this->money($doc, $amt, 'prov_pip_amt', $slip['box55']);     // Box 55 (QPIP premiums)
        $this->money($doc, $amt, 'prov_insur_ern_amt', $slip['box56']); // Box 56 (QPIP insurable)

        // Other Information amounts with dedicated, long-standing T4_AMT
        // elements. Remaining other-info codes (taxable benefits 40, auto 34,
        // …) have per-code OTH_INFO elements that change with the yearly
        // schema — verify and extend before filing slips that carry them
        // (they still appear on the printed slips and the CSV).
        $other = array_map('intval', (array) ($slip['other'] ?? []));
        $this->money($doc, $amt, 'rpp_cntrb_amt', $other['20'] ?? 0);   // Box 20 (RPP contributions)
        $this->money($doc, $amt, 'unn_dues_amt', $other['44'] ?? 0);    // Box 44 (union dues)
        $this->money($doc, $amt, 'chrty_dons_amt', $other['46'] ?? 0);  // Box 46 (charitable donations)
        $this->money($doc, $amt, 'pens_adjt_amt', $other['52'] ?? 0);   // Box 52 (pension adjustment)
        $t4Slip->appendChild($amt);

        return $t4Slip;
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function summary(DOMDocument $doc, Company $company, int $year, array $summary, int $slipCount): DOMElement
    {
        $t4Summary = $doc->createElement('T4Summary');
        $this->add($doc, $t4Summary, 'bn', $this->bn((string) $company->tax_number));

        $name = $doc->createElement('EMPR_NM');
        $this->add($doc, $name, 'l1_nm', $this->clean($company->name, 30));
        $t4Summary->appendChild($name);

        $this->add($doc, $t4Summary, 'tx_yr', (string) $year);
        $this->add($doc, $t4Summary, 'slp_cnt', (string) $slipCount);
        $this->add($doc, $t4Summary, 'rpt_tcd', 'O');

        $tamt = $doc->createElement('T4_TAMT');
        $this->money($doc, $tamt, 'tot_empt_incamt', $summary['box14']);
        $this->money($doc, $tamt, 'tot_cpp_cntrb_amt', $summary['box16']);
        $this->money($doc, $tamt, 'tot_cpp2_cntrb_amt', $summary['box16a']);
        $this->money($doc, $tamt, 'tot_qpp_cntrb_amt', $summary['box17']);
        $this->money($doc, $tamt, 'tot_empe_eip_amt', $summary['box18']);
        $this->money($doc, $tamt, 'tot_itx_ddct_amt', $summary['box22']);
        $this->money($doc, $tamt, 'tot_prov_pip_amt', $summary['box55']);
        $this->money($doc, $tamt, 'tot_empr_cpp_amt', $summary['employer_cpp']);
        $this->money($doc, $tamt, 'tot_empr_eip_amt', $summary['employer_ei']);
        $t4Summary->appendChild($tamt);

        return $t4Summary;
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

    private function sin(?EmployeePayrollProfile $profile): string
    {
        try {
            // getAttribute runs the 'encrypted' cast, which throws when the
            // ciphertext no longer decrypts (rotated APP_KEY / corrupt row).
            $sin = $profile?->getAttribute('sin_encrypted');
        } catch (DecryptException) {
            // File the unknown-SIN placeholder rather than failing the export.
            $sin = null;
        }

        return $sin !== null && $sin !== '' ? preg_replace('/\D/', '', $sin) : '000000000';
    }

    private function bn(string $taxNumber): string
    {
        $clean = preg_replace('/\s+/', '', $taxNumber);

        return $clean !== '' ? $clean : '000000000RP0000';
    }

    private function clean(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }

    private function postal(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $value));
    }
}
