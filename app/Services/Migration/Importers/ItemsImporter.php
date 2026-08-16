<?php

namespace App\Services\Migration\Importers;

use App\Enums\ItemType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\TaxCode;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class ItemsImporter implements CompanyCsvImporter, Importer
{
    public function __construct(protected CsvParser $parser) {}

    public function templateHeaders(): array
    {
        return [
            'sku', 'name', 'description', 'type', 'item_category',
            'is_inventory', 'income_account_code', 'expense_account_code',
            'inventory_asset_account_code', 'cogs_account_code',
            'default_price', 'default_tax_code', 'reorder_point',
        ];
    }

    public function templateExampleRows(): array
    {
        return [
            ['sku' => 'WIDGET-001', 'name' => 'Widget', 'description' => 'Standard widget', 'type' => 'inventory', 'item_category' => 'Hardware', 'is_inventory' => 'yes', 'income_account_code' => '4000', 'expense_account_code' => '', 'inventory_asset_account_code' => '1400', 'cogs_account_code' => '5000', 'default_price' => '49.99', 'default_tax_code' => 'GST', 'reorder_point' => '10'],
            ['sku' => 'SVC-CONSULT', 'name' => 'Consulting hour', 'description' => 'Hourly consulting', 'type' => 'service', 'item_category' => 'Services', 'is_inventory' => 'no', 'income_account_code' => '4100', 'expense_account_code' => '', 'inventory_asset_account_code' => '', 'cogs_account_code' => '', 'default_price' => '150.00', 'default_tax_code' => '', 'reorder_point' => ''],
        ];
    }

    public function preview(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx->company, true);
    }

    public function commit(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx->company, false);
    }

    public function previewForCompany(string $csvPath, Company $company): ImportResult
    {
        return $this->run($csvPath, $company, true);
    }

    public function commitForCompany(string $csvPath, Company $company): ImportResult
    {
        return $this->run($csvPath, $company, false);
    }

    protected function run(string $csvPath, Company $company, bool $dryRun): ImportResult
    {
        $rows = $this->parser->parse($csvPath, ['name'], $this->templateHeaders());
        $errors = [];
        $preview = [];
        $createdIds = [];
        $created = 0;
        $skipped = 0;

        $accountByCode = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->pluck('id', 'code');

        $taxCodeByCode = TaxCode::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->pluck('id', 'code');

        // SKU is the chosen identity for skip-existing (items have no unique
        // constraint): a row whose SKU already exists is skipped; blank SKUs
        // always create. Updated as the run proceeds so an in-file duplicate
        // SKU is skipped too.
        $seenSkus = Item::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNotNull('sku')
            ->pluck('id', 'sku')
            ->all();

        // Existing categories keyed by lower-cased name; unknown ones are
        // auto-created on commit and cached here so repeats share one row.
        $categoryByName = [];
        foreach (ItemCategory::withoutGlobalScopes()->where('company_id', $company->id)->get(['id', 'name']) as $cat) {
            $categoryByName[mb_strtolower((string) $cat->name)] = $cat->id;
        }

        $validTypes = array_map(fn (ItemType $t) => $t->value, ItemType::cases());

        $runner = function () use ($rows, $company, &$errors, &$preview, &$createdIds, &$created, &$skipped, $dryRun, $accountByCode, $taxCodeByCode, &$seenSkus, &$categoryByName, $validTypes): void {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $name = $row['name'];

                if (! $name) {
                    $errors[] = ['row' => $rowNum, 'message' => 'name is required.'];

                    continue;
                }

                $sku = $row['sku'];

                if ($sku && isset($seenSkus[$sku])) {
                    $skipped++;
                    $preview[] = ['row' => $rowNum, 'sku' => $sku, 'name' => $name, 'action' => 'skip (exists)'];

                    continue;
                }

                // From here the row will create; reserve its SKU so a later row
                // reusing it (in this file) is skipped rather than duplicated.
                if ($sku) {
                    $seenSkus[$sku] = true;
                }

                $errorsBefore = count($errors);
                $isInventory = CsvParser::parseBool($row['is_inventory']);

                $type = $isInventory ? ItemType::Inventory : ItemType::Service;
                if ($row['type']) {
                    $typeValue = strtolower($row['type']);

                    if (! in_array($typeValue, $validTypes, true)) {
                        $errors[] = ['row' => $rowNum, 'message' => "Unknown type '{$typeValue}'. Valid values: ".implode(', ', $validTypes)];
                    } else {
                        $type = ItemType::from($typeValue);
                    }
                }

                $tracksInventory = $isInventory || $type === ItemType::Inventory;

                $resolveAccount = function (?string $code, string $field) use ($accountByCode, $rowNum, &$errors): ?int {
                    if (! $code) {
                        return null;
                    }

                    if (! isset($accountByCode[$code])) {
                        $errors[] = ['row' => $rowNum, 'message' => "Account code '{$code}' for {$field} not found."];

                        return null;
                    }

                    return (int) $accountByCode[$code];
                };

                $incomeId = $resolveAccount($row['income_account_code'], 'income_account_code');
                $expenseId = $resolveAccount($row['expense_account_code'], 'expense_account_code');
                $invAssetId = $resolveAccount($row['inventory_asset_account_code'], 'inventory_asset_account_code');
                $cogsId = $resolveAccount($row['cogs_account_code'], 'cogs_account_code');

                $taxCodeId = null;
                if ($row['default_tax_code']) {
                    if (! isset($taxCodeByCode[$row['default_tax_code']])) {
                        $errors[] = ['row' => $rowNum, 'message' => "Tax code '{$row['default_tax_code']}' not found."];
                    } else {
                        $taxCodeId = (int) $taxCodeByCode[$row['default_tax_code']];
                    }
                }

                // Resolve (or, on commit, auto-create) the category by name.
                $categoryId = null;
                if ($row['item_category']) {
                    $key = mb_strtolower($row['item_category']);

                    if (isset($categoryByName[$key])) {
                        $categoryId = $categoryByName[$key];
                    } elseif (! $dryRun) {
                        $newCategory = ItemCategory::withoutGlobalScopes()->create([
                            'company_id' => $company->id,
                            'name' => $row['item_category'],
                            'is_active' => true,
                        ]);
                        $categoryByName[$key] = $newCategory->id;
                        $categoryId = $newCategory->id;
                    }
                }

                $preview[] = ['row' => $rowNum, 'sku' => $sku, 'name' => $name, 'action' => 'create'];

                if ($dryRun || count($errors) > $errorsBefore) {
                    continue;
                }

                $item = Item::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'sku' => $sku,
                    'name' => $name,
                    'description' => $row['description'],
                    'type' => $type,
                    'item_category_id' => $categoryId,
                    'track_inventory' => $tracksInventory,
                    'income_account_id' => $incomeId,
                    'expense_account_id' => $expenseId,
                    'inventory_asset_account_id' => $tracksInventory ? $invAssetId : null,
                    'cogs_account_id' => $tracksInventory ? $cogsId : null,
                    'default_price_cents' => CsvParser::parseCents($row['default_price']) ?? 0,
                    'default_tax_code_id' => $taxCodeId,
                    'reorder_point' => $row['reorder_point'],
                    'qty_on_hand_cached' => 0,
                    'unit_cost_cents_cached' => 0,
                    'is_active' => true,
                ]);

                if ($sku) {
                    $seenSkus[$sku] = $item->id;
                }

                $created++;
                $createdIds[] = $item->id;
            }
        };

        if ($dryRun) {
            $runner();
        } else {
            try {
                DB::transaction($runner);
            } catch (Throwable $e) {
                $errors[] = ['row' => 0, 'message' => 'Import aborted: '.$e->getMessage()];
            }
        }

        return new ImportResult(
            isDryRun: $dryRun,
            previewRows: $preview,
            errors: $errors,
            createdIds: $createdIds,
            summary: ['created' => $created, 'skipped_existing' => $skipped, 'rows' => count($rows)],
        );
    }
}
