<?php

namespace App\Services\Deletion;

use Illuminate\Support\Facades\DB;

/**
 * A document's lines as they stood, read straight from its line table and
 * made readable: account code and name, item and SKU, tax codes, amounts.
 * The same shape for every document type, so the deleted transaction's page
 * shows any of them.
 */
final class LineSnapshot
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function fromTable(string $table, string $foreignKey, int $documentId): array
    {
        $rows = DB::table($table)->where($foreignKey, $documentId)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $first = (array) $rows->first();
        $rows = array_key_exists('line_order', $first) ? $rows->sortBy('line_order')->values() : $rows;

        $ids = fn (string $column) => $rows->pluck($column)->filter()->unique()->values()->all();
        $accounts = array_key_exists('account_id', $first) ? DB::table('accounts')->whereIn('id', $ids('account_id'))->get(['id', 'code', 'name'])->keyBy('id') : collect();
        $items = array_key_exists('item_id', $first) ? DB::table('items')->whereIn('id', $ids('item_id'))->get(['id', 'name', 'sku'])->keyBy('id') : collect();
        $taxIds = array_merge(
            array_key_exists('tax_code_id', $first) ? $ids('tax_code_id') : [],
            array_key_exists('secondary_tax_code_id', $first) ? $ids('secondary_tax_code_id') : [],
        );
        $taxCodes = DB::table('tax_codes')->whereIn('id', $taxIds)->pluck('code', 'id');

        return $rows->map(function (object $row) use ($accounts, $items, $taxCodes): array {
            $line = (array) $row;
            $account = isset($line['account_id']) ? $accounts->get($line['account_id']) : null;
            $item = isset($line['item_id']) ? $items->get($line['item_id']) : null;

            return [
                'description' => $line['description'] ?? null,
                'item' => $item->name ?? null,
                'sku' => $item->sku ?? null,
                'account_code' => $account->code ?? null,
                'account_name' => $account->name ?? null,
                'quantity' => isset($line['quantity']) ? (string) $line['quantity'] : null,
                'unit_price_cents' => isset($line['unit_price_cents']) ? (int) $line['unit_price_cents'] : null,
                'tax_code' => isset($line['tax_code_id']) ? ($taxCodes[$line['tax_code_id']] ?? null) : null,
                'secondary_tax_code' => isset($line['secondary_tax_code_id']) ? ($taxCodes[$line['secondary_tax_code_id']] ?? null) : null,
                'line_total_cents' => (int) ($line['line_total_cents'] ?? $line['amount_cents'] ?? 0),
            ];
        })->all();
    }

    /**
     * Every tax code on the document's lines, primary and secondary.
     *
     * @return list<int>
     */
    public static function taxCodeIds(string $table, string $foreignKey, int $documentId): array
    {
        $ids = [];

        foreach (DB::table($table)->where($foreignKey, $documentId)->get() as $row) {
            foreach (['tax_code_id', 'secondary_tax_code_id'] as $column) {
                if (isset($row->{$column})) {
                    $ids[] = (int) $row->{$column};
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
