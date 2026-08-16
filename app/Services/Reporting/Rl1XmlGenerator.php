<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use DOMDocument;
use DOMElement;

/**
 * Generates a Revenu Québec RL-1 (Relevé 1) electronic-filing XML document for a
 * tax year: a transmitter record, one Releve1 per Quebec employee, and a summary
 * (Sommaire) carrying the box totals and the WSDRF reconciliation. Amounts are
 * emitted in dollars (2 decimals).
 *
 * IMPORTANT — the Revenu Québec RL-1 XML schema is revised yearly and validation
 * is strict. The transmitter number (config payroll.rl1.transmitter_number) must
 * be your Revenu Québec-assigned "numéro d'identification" (NPxxxxxx), and the
 * output should be validated against the current Revenu Québec schema before
 * submission. Slip sequence numbers ("numéro du relevé") are assigned by Revenu
 * Québec; the sequential numbers here are placeholders until you obtain a range.
 */
class Rl1XmlGenerator
{
    /**
     * @param  array<int, array<string, mixed>>  $slips  From {@see Rl1SlipCalculator::slipsForYear()}
     * @param  array<string, mixed>  $summary  From {@see Rl1SlipCalculator::summary()}
     */
    public function generate(Company $company, int $year, array $slips, array $summary): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $transmission = $doc->createElement('Transmission');
        $doc->appendChild($transmission);

        $transmission->appendChild($this->transmitter($doc, $company, $year, count($slips)));

        $releves = $doc->createElement('RELEVES1');
        $transmission->appendChild($releves);

        $sequence = 1;

        foreach ($slips as $slip) {
            $releves->appendChild($this->slip($doc, $company, $year, $slip, $sequence++));
        }

        $releves->appendChild($this->summary($doc, $company, $year, $summary, count($slips)));

        return (string) $doc->saveXML();
    }

    private function transmitter(DOMDocument $doc, Company $company, int $year, int $slipCount): DOMElement
    {
        $t = $doc->createElement('Transmetteur');
        $this->add($doc, $t, 'NoIdentification', (string) config('payroll.rl1.transmitter_number'));
        $this->add($doc, $t, 'NoPreparateur', (string) config('payroll.rl1.preparer_number'));
        $this->add($doc, $t, 'TypeReleve', (string) config('payroll.rl1.slip_type'));
        $this->add($doc, $t, 'AnneeImposition', (string) $year);
        $this->add($doc, $t, 'NbReleves', (string) $slipCount);

        $name = $doc->createElement('Nom');
        $this->add($doc, $name, 'Ligne1', $this->clean($company->name, 30));
        $t->appendChild($name);

        $addr = $doc->createElement('Adresse');
        $this->add($doc, $addr, 'Ligne1', $this->clean((string) $company->address_line1, 30));
        $this->add($doc, $addr, 'Ville', $this->clean((string) $company->address_city, 28));
        $this->add($doc, $addr, 'Province', (string) ($company->address_region ?: 'QC'));
        $this->add($doc, $addr, 'CodePostal', $this->postal((string) $company->address_postal_code));
        $t->appendChild($addr);

        return $t;
    }

    /**
     * @param  array<string, mixed>  $slip
     */
    private function slip(DOMDocument $doc, Company $company, int $year, array $slip, int $sequence): DOMElement
    {
        $contact = Contact::query()->withoutGlobalScopes()->find($slip['contact_id']);
        $profile = EmployeePayrollProfile::query()->withoutGlobalScopes()
            ->where('contact_id', $slip['contact_id'])->first();

        $releve = $doc->createElement('Releve1');
        $this->add($doc, $releve, 'NoReleve', str_pad((string) $sequence, 9, '0', STR_PAD_LEFT));
        $this->add($doc, $releve, 'TypeReleve', (string) config('payroll.rl1.slip_type'));
        $this->add($doc, $releve, 'AnneeImposition', (string) $year);
        $this->add($doc, $releve, 'NoIdentificationQc', $this->qcId((string) $company->tax_number));

        $name = $doc->createElement('NomParticulier');
        $this->add($doc, $name, 'NomFamille', $this->clean((string) ($contact?->last_name ?: $slip['name']), 30));
        $this->add($doc, $name, 'Prenom', $this->clean((string) ($contact?->first_name ?? ''), 30));
        $releve->appendChild($name);

        $this->add($doc, $releve, 'NoAssuranceSociale', $this->sin($profile));

        // RL-1 monetary boxes.
        $boxes = $doc->createElement('Montants');
        $this->money($doc, $boxes, 'CaseA', $slip['boxA']); // Employment income
        $this->money($doc, $boxes, 'CaseB', $slip['boxB']); // QPP contribution
        $this->money($doc, $boxes, 'CaseE', $slip['boxE']); // Quebec income tax
        $this->money($doc, $boxes, 'CaseG', $slip['boxG']); // QPP pensionable salary
        $this->money($doc, $boxes, 'CaseH', $slip['boxH']); // QPIP premium
        $this->money($doc, $boxes, 'CaseI', $slip['boxI']); // QPIP insurable salary
        $releve->appendChild($boxes);

        return $releve;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function summary(DOMDocument $doc, Company $company, int $year, array $summary, int $slipCount): DOMElement
    {
        $sommaire = $doc->createElement('Sommaire1');
        $this->add($doc, $sommaire, 'NoIdentificationQc', $this->qcId((string) $company->tax_number));
        $this->add($doc, $sommaire, 'AnneeImposition', (string) $year);
        $this->add($doc, $sommaire, 'NbReleves', (string) $slipCount);

        $name = $doc->createElement('NomEmployeur');
        $this->add($doc, $name, 'Ligne1', $this->clean($company->name, 30));
        $sommaire->appendChild($name);

        $tamt = $doc->createElement('MontantsTotaux');
        $this->money($doc, $tamt, 'TotalCaseA', (int) $summary['boxA']);
        $this->money($doc, $tamt, 'TotalCaseB', (int) $summary['boxB']);
        $this->money($doc, $tamt, 'TotalCaseE', (int) $summary['boxE']);
        $this->money($doc, $tamt, 'TotalCaseG', (int) $summary['boxG']);
        $this->money($doc, $tamt, 'TotalCaseH', (int) $summary['boxH']);
        $this->money($doc, $tamt, 'TotalCaseI', (int) $summary['boxI']);
        $this->money($doc, $tamt, 'CotisationEmployeurRRQ', (int) $summary['employer_qpp']);
        $this->money($doc, $tamt, 'CotisationEmployeurRQAP', (int) $summary['employer_qpip']);
        $this->money($doc, $tamt, 'CotisationFSS', (int) $summary['qhsf']);
        $sommaire->appendChild($tamt);

        // WSDRF (workforce skills development) reconciliation.
        $wsdrf = $doc->createElement('FormationMainDoeuvre');
        $this->add($doc, $wsdrf, 'Assujetti', $summary['wsdrf_applicable'] ? 'O' : 'N');
        $this->money($doc, $wsdrf, 'MasseSalariale', (int) $summary['wsdrf_payroll_cents']);
        $this->money($doc, $wsdrf, 'DepensesFormation', (int) $summary['wsdrf_training_cents']);
        $this->money($doc, $wsdrf, 'CotisationDue', (int) $summary['wsdrf_levy_cents']);
        $sommaire->appendChild($wsdrf);

        return $sommaire;
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
        $sin = $profile?->sin_encrypted; // decrypted by the cast

        return $sin !== null && $sin !== '' ? preg_replace('/\D/', '', $sin) : '000000000';
    }

    private function qcId(string $taxNumber): string
    {
        $clean = preg_replace('/\s+/', '', $taxNumber);

        return $clean !== '' ? $clean : '0000000000';
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
