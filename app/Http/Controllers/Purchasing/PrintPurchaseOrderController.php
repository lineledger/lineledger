<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Services\Reporting\PdfExporter;
use App\Support\Tax\LineTaxBreakdown;
use Illuminate\Http\Response;

class PrintPurchaseOrderController extends Controller
{
    public function __invoke(Company $company, PurchaseOrder $purchaseOrder, PdfExporter $pdf): Response
    {
        abort_unless($purchaseOrder->company_id === $company->id, 404);

        $purchaseOrder->loadMissing('lines.taxCode', 'lines.item', 'contact', 'terms');

        $filename = 'purchase-order-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $purchaseOrder->po_no).'.pdf';

        return $pdf->inline('pdf.purchase-orders.purchase-order', [
            'company' => $company,
            'purchaseOrder' => $purchaseOrder,
            'settings' => $company->invoiceSettingsOrNew(),
            'taxSummary' => $this->taxSummary($purchaseOrder),
        ], $filename);
    }

    /**
     * Per-tax-code summary rows, built by grouping the lines on their tax code
     * and summing the line tax.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    private function taxSummary(PurchaseOrder $purchaseOrder): array
    {
        return LineTaxBreakdown::forLines($purchaseOrder->lines);
    }
}
