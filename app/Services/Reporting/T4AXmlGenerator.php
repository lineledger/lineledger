<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Models\Contact;
use DOMDocument;
use DOMElement;

/**
 * Generates a CRA T4A electronic-filing XML document (Internet File Transfer
 * layout) for a tax year: a T619 transmitter record, one T4ASlip per reportable
 * contractor (fees for services, Box 048), and a T4ASummary. The Canadian analog
 * of the 1099 e-file; sibling of {@see T4XmlGenerator}.
 *
 * IMPORTANT — the CRA T4A XML schema is revised yearly and validation is strict.
 * The transmitter number (config payroll.transmitter.number) must be your
 * CRA-assigned "MM" account, and the output should be validated against the
 * current CRA schema before submission.
 */
class T4AXmlGenerator
{
    /**
     * @param  array<int, array<string, mixed>>  $slips  From {@see T4ASlipCalculator::slipsForYear()}
     * @param  array{slip_count: int, box048: int}  $summary  From {@see T4ASlipCalculator::summary()}
     */
    public function generate(Company $company, int $year, array $slips, array $summary): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $submission = $doc->createElement('Submission');
        $doc->appendChild($submission);

        $submission->appendChild($this->transmitter($doc, $company));

        $return = $doc->createElement('Return');
        $submission->appendChild($return);

        $t4a = $doc->createElement('T4A');
        $return->appendChild($t4a);

        foreach ($slips as $slip) {
            $t4a->appendChild($this->slip($doc, $company, $slip));
        }

        $t4a->appendChild($this->summary($doc, $company, $year, $summary, count($slips)));

        return (string) $doc->saveXML();
    }

    private function transmitter(DOMDocument $doc, Company $company): DOMElement
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

        $t4aSlip = $doc->createElement('T4ASlip');

        // Recipient name: individual surname/given, else the business name in line 1.
        // The slip's contact always exists (it is the slip's source row).
        $name = $doc->createElement('RCPNT_NM');
        $this->add($doc, $name, 'snm', $this->clean((string) ($contact->last_name ?: $slip['name']), 30));
        $this->add($doc, $name, 'gvn_nm', $this->clean((string) ($contact->first_name ?? ''), 12));
        $t4aSlip->appendChild($name);

        // A 9-digit number is a recipient SIN; anything else is a recipient BN.
        [$sin, $rcpntBn] = $this->recipientIds((string) ($slip['tax_number'] ?? ''));
        $this->add($doc, $t4aSlip, 'sin', $sin);

        if ($rcpntBn !== '') {
            $this->add($doc, $t4aSlip, 'rcpnt_bn', $rcpntBn);
        }

        $this->add($doc, $t4aSlip, 'bn', $this->bn((string) $company->tax_number)); // payer BN
        $this->add($doc, $t4aSlip, 'rpt_tcd', 'O');

        $amt = $doc->createElement('T4A_AMT');
        $this->money($doc, $amt, 'fees_srvc_amt', (int) $slip['box048_cents']); // Box 048 — fees for services
        $t4aSlip->appendChild($amt);

        return $t4aSlip;
    }

    /**
     * @param  array{slip_count: int, box048: int}  $summary
     */
    private function summary(DOMDocument $doc, Company $company, int $year, array $summary, int $slipCount): DOMElement
    {
        $t4aSummary = $doc->createElement('T4ASummary');
        $this->add($doc, $t4aSummary, 'bn', $this->bn((string) $company->tax_number));

        $name = $doc->createElement('PAYR_NM');
        $this->add($doc, $name, 'l1_nm', $this->clean($company->name, 30));
        $t4aSummary->appendChild($name);

        $this->add($doc, $t4aSummary, 'tx_yr', (string) $year);
        $this->add($doc, $t4aSummary, 'slp_cnt', (string) $slipCount);
        $this->add($doc, $t4aSummary, 'rpt_tcd', 'O');

        $tamt = $doc->createElement('T4A_TAMT');
        $this->money($doc, $tamt, 'tot_fees_srvc_amt', (int) $summary['box048']);
        $t4aSummary->appendChild($tamt);

        return $t4aSummary;
    }

    /**
     * Split a contractor's tax number into [sin, recipient BN]: a bare 9-digit
     * value is a SIN; anything else (a 15-char BN/RP) is a recipient BN.
     *
     * @return array{0: string, 1: string}
     */
    private function recipientIds(string $taxNumber): array
    {
        $clean = preg_replace('/\s+/', '', $taxNumber) ?? '';

        if (preg_match('/^\d{9}$/', $clean) === 1) {
            return [$clean, ''];
        }

        return ['000000000', $clean];
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

    private function bn(string $taxNumber): string
    {
        $clean = preg_replace('/\s+/', '', $taxNumber);

        return $clean !== '' && $clean !== null ? $clean : '000000000RP0000';
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
