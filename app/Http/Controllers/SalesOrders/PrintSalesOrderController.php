<?php

namespace App\Http\Controllers\SalesOrders;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SalesOrder;
use App\Services\Reporting\PdfExporter;
use App\Support\Tax\LineTaxBreakdown;
use Illuminate\Http\Response;

class PrintSalesOrderController extends Controller
{
    public function __invoke(Company $company, SalesOrder $salesOrder, PdfExporter $pdf): Response
    {
        abort_unless($salesOrder->company_id === $company->id, 404);

        $salesOrder->loadMissing('lines.taxCode', 'lines.item', 'contact', 'terms', 'salesRep');

        $filename = 'sales-order-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $salesOrder->order_no).'.pdf';

        return $pdf->inline('pdf.sales-orders.sales-order', [
            'company' => $company,
            'salesOrder' => $salesOrder,
            'settings' => $company->invoiceSettingsOrNew(),
            'taxSummary' => $this->taxSummary($salesOrder),
        ], $filename);
    }

    /**
     * Per-tax-code summary rows, built by grouping the lines on their tax code
     * and summing the line tax.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    private function taxSummary(SalesOrder $salesOrder): array
    {
        return LineTaxBreakdown::forLines($salesOrder->lines);
    }
}
