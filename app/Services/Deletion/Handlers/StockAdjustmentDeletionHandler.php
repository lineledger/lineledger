<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\SnapshotValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a stock adjustment. Its stock movements come out with it
 * (TransactionDeleter), which the stock of an item moved since refuses.
 */
final class StockAdjustmentDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::StockAdjustment;
    }

    protected function lines(): ?array
    {
        return null;
    }

    protected function contactColumn(): ?string
    {
        return null;
    }

    public function summary(Model $document): array
    {
        $this->own($document);

        return [
            'document_number' => $this->stringOrNull($document->getAttribute('adjustment_no')),
            'document_date' => SnapshotValue::date($document->getAttribute('adjustment_date')),
            'contact_name' => null,
            'total_cents' => (int) collect($this->lineRows($document))->sum(fn (object $line): int => (int) round((float) $line->qty_change * (int) $line->unit_cost_cents)),
            'currency_code' => null,
        ];
    }

    protected function extraSnapshot(Model $document): array
    {
        $rows = $this->lineRows($document);
        $itemIds = array_values(array_filter(array_map(fn (object $line): mixed => $line->item_id ?? null, $rows)));
        $items = DB::table('items')->whereIn('id', $itemIds)->get(['id', 'name', 'sku'])->keyBy('id');
        $lines = [];

        foreach ($rows as $line) {
            $item = $items->get($line->item_id ?? 0);
            $quantity = (float) ($line->qty_change ?? 0);
            $cost = (int) ($line->unit_cost_cents ?? 0);

            $lines[] = [
                'description' => $line->notes ?? null,
                'item' => is_object($item) ? $item->name : null,
                'sku' => is_object($item) ? $item->sku : null,
                'account_code' => null,
                'account_name' => null,
                'quantity' => (string) ($line->qty_change ?? ''),
                'unit_price_cents' => $cost,
                'tax_code' => null,
                'secondary_tax_code' => null,
                'line_total_cents' => (int) round($quantity * $cost),
            ];
        }

        return [
            'memo' => $this->stringOrNull($document->getAttribute('notes')),
            'reason' => $this->stringOrNull($document->getAttribute('reason')),
            'lines' => $lines,
        ];
    }

    protected function beforePurge(Model $document, DeletionWork $work): void
    {
        DB::table('stock_adjustment_lines')->where('stock_adjustment_id', $document->getKey())->delete();
    }

    /**
     * @return list<object>
     */
    private function lineRows(Model $document): array
    {
        return DB::table('stock_adjustment_lines')->where('stock_adjustment_id', $document->getKey())->orderBy('line_order')->get()->all();
    }
}
