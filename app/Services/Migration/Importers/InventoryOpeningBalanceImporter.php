<?php

namespace App\Services\Migration\Importers;

use App\Enums\StockAdjustmentReason;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use App\Services\Posting\StockAdjustmentPoster;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports opening on-hand inventory by building a single StockAdjustment
 * (reason=OpeningBalance) and posting it through the existing
 * StockAdjustmentPoster — which already routes the offset to OBE.
 *
 * Each CSV row is one item; rows produce StockAdjustmentLines with a
 * positive qty_change and the QB unit cost.
 */
class InventoryOpeningBalanceImporter implements Importer
{
    public function __construct(
        protected CsvParser $parser,
        protected StockAdjustmentPoster $stockPoster,
    ) {}

    public function templateHeaders(): array
    {
        return ['sku', 'qty_on_hand', 'unit_cost'];
    }

    public function templateExampleRows(): array
    {
        return [
            ['sku' => 'WIDGET-001', 'qty_on_hand' => '50', 'unit_cost' => '12.50'],
            ['sku' => 'GADGET-002', 'qty_on_hand' => '12', 'unit_cost' => '85.00'],
        ];
    }

    public function preview(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, true);
    }

    public function commit(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, false);
    }

    protected function run(string $csvPath, ImportContext $ctx, bool $dryRun): ImportResult
    {
        $rows = $this->parser->parse($csvPath, ['sku', 'qty_on_hand', 'unit_cost'], $this->templateHeaders());
        $errors = [];
        $preview = [];
        $createdIds = [];

        $itemsBySku = Item::withoutGlobalScopes()
            ->where('company_id', $ctx->company->id)
            ->whereNotNull('sku')
            ->get()
            ->keyBy('sku');

        $accepted = [];
        $totalValueCents = 0;

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $sku = $row['sku'];
            $qty = (float) ($row['qty_on_hand'] ?? 0);
            $unitCostCents = CsvParser::parseCents($row['unit_cost']);

            if (! $sku || $unitCostCents === null) {
                $errors[] = ['row' => $rowNum, 'message' => 'sku, qty_on_hand and unit_cost are required.'];

                continue;
            }

            if ($qty <= 0) {
                $errors[] = ['row' => $rowNum, 'message' => 'qty_on_hand must be greater than zero.'];

                continue;
            }

            $item = $itemsBySku->get($sku);

            if (! $item) {
                $errors[] = ['row' => $rowNum, 'message' => "Item SKU '{$sku}' not found. Import items before inventory opening balances."];

                continue;
            }

            if (! $item->track_inventory) {
                $errors[] = ['row' => $rowNum, 'message' => "Item '{$item->name}' (SKU {$sku}) does not track inventory."];

                continue;
            }

            $accepted[] = ['item' => $item, 'qty' => $qty, 'unit_cost_cents' => $unitCostCents];

            $preview[] = [
                'row' => $rowNum,
                'sku' => $sku,
                'item' => $item->name,
                'qty' => number_format($qty, 4),
                'unit_cost' => CsvParser::centsLabel($unitCostCents),
                'value' => CsvParser::centsLabel((int) round($qty * $unitCostCents)),
            ];

            $totalValueCents += (int) round($qty * $unitCostCents);
        }

        if ($dryRun || $errors !== [] || $accepted === []) {
            return new ImportResult(
                isDryRun: $dryRun,
                previewRows: $preview,
                errors: $errors,
                createdIds: $createdIds,
                summary: [
                    'rows' => count($rows),
                    'accepted' => count($accepted),
                    'total_value_cents' => $totalValueCents,
                ],
            );
        }

        try {
            DB::transaction(function () use ($accepted, $ctx, &$createdIds): void {
                $adjustment = StockAdjustment::withoutGlobalScopes()->create([
                    'company_id' => $ctx->company->id,
                    'adjustment_no' => $this->stockPoster->nextAdjustmentNumber($ctx->company),
                    'adjustment_date' => $ctx->conversionDate,
                    'reason' => StockAdjustmentReason::OpeningBalance,
                    'notes' => 'Opening inventory balance — carried over from QuickBooks',
                ]);

                foreach ($accepted as $i => $row) {
                    $adjustment->lines()->create([
                        'item_id' => $row['item']->id,
                        'qty_change' => $row['qty'],
                        'unit_cost_cents' => $row['unit_cost_cents'],
                        'line_order' => $i,
                    ]);
                }

                $this->stockPoster->post($adjustment->fresh());
                $createdIds[] = $adjustment->id;
            });
        } catch (Throwable $e) {
            $errors[] = ['row' => 0, 'message' => 'Import aborted: '.$e->getMessage()];
        }

        return new ImportResult(
            isDryRun: $dryRun,
            previewRows: $preview,
            errors: $errors,
            createdIds: $createdIds,
            summary: [
                'rows' => count($rows),
                'accepted' => count($accepted),
                'total_value_cents' => $totalValueCents,
            ],
        );
    }
}
