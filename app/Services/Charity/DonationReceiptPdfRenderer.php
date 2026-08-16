<?php

namespace App\Services\Charity;

use App\Models\Company;
use App\Models\DonationReceipt;
use App\Services\Reporting\PdfExporter;
use Illuminate\Http\Response;

/**
 * Renders an official CRA donation receipt to PDF. Single source for the print
 * route and email attachment so the document is identical everywhere.
 */
class DonationReceiptPdfRenderer
{
    public function __construct(protected PdfExporter $pdf) {}

    public function filename(DonationReceipt $receipt): string
    {
        return 'donation-receipt-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $receipt->receipt_no).'.pdf';
    }

    public function inline(Company $company, DonationReceipt $receipt): Response
    {
        return $this->pdf->inline('pdf.donations.receipt', $this->data($company, $receipt), $this->filename($receipt));
    }

    public function raw(Company $company, DonationReceipt $receipt): string
    {
        return $this->pdf->raw('pdf.donations.receipt', $this->data($company, $receipt));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Company $company, DonationReceipt $receipt): array
    {
        $receipt->loadMissing('contact');

        return [
            'company' => $company,
            'receipt' => $receipt,
            'charityNumber' => $company->charity_registration_number,
        ];
    }
}
