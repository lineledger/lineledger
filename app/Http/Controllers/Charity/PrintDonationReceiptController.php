<?php

namespace App\Http\Controllers\Charity;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DonationReceipt;
use App\Services\Charity\DonationReceiptPdfRenderer;
use Illuminate\Http\Response;

class PrintDonationReceiptController extends Controller
{
    public function __invoke(Company $company, DonationReceipt $donationReceipt, DonationReceiptPdfRenderer $renderer): Response
    {
        abort_unless($donationReceipt->company_id === $company->id, 404);
        abort_unless($company->isRegisteredCharity(), 403);
        abort_unless($donationReceipt->isIssued() || $donationReceipt->status->value === 'void', 403);

        return $renderer->inline($company, $donationReceipt);
    }
}
