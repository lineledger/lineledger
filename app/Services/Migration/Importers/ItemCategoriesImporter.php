<?php

namespace App\Services\Migration\Importers;

use App\Models\Company;
use App\Models\ItemCategory;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports item categories (QuickBooks "Categories") from a CSV. Existing names
 * are left untouched; new names are created. A category can nest under another
 * by naming its parent — list the parent's row before its children.
 */
class ItemCategoriesImporter implements CompanyCsvImporter, Importer
{
    public function __construct(protected CsvParser $parser) {}

    public function templateHeaders(): array
    {
        return ['name', 'parent_name', 'is_active'];
    }

    public function templateExampleRows(): array
    {
        return [
            ['name' => 'Caskets', 'parent_name' => '', 'is_active' => 'yes'],
            ['name' => 'Wood Caskets', 'parent_name' => 'Caskets', 'is_active' => 'yes'],
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
        try {
            $rows = $this->parser->parse($csvPath, ['name'], $this->templateHeaders());
        } catch (Throwable $e) {
            return new ImportResult(isDryRun: $dryRun, previewRows: [], errors: [['row' => 0, 'message' => $e->getMessage()]]);
        }

        $errors = [];
        $preview = [];
        $createdIds = [];
        $created = 0;
        $skipped = 0;

        // Existing categories by lower-cased name. New names are added to the
        // map as they are created so a later row can nest under one of them.
        $idByName = [];
        foreach (ItemCategory::withoutGlobalScopes()->where('company_id', $company->id)->get(['id', 'name']) as $cat) {
            $idByName[mb_strtolower((string) $cat->name)] = $cat->id;
        }

        $runner = function () use ($rows, $company, &$errors, &$preview, &$createdIds, &$created, &$skipped, $dryRun, &$idByName): void {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $name = $row['name'];

                if (! $name) {
                    $errors[] = ['row' => $rowNum, 'message' => 'name is required.'];

                    continue;
                }

                $key = mb_strtolower($name);

                if (isset($idByName[$key])) {
                    $skipped++;
                    $preview[] = ['row' => $rowNum, 'name' => $name, 'action' => 'skip (exists)'];

                    continue;
                }

                $parentId = null;
                if ($row['parent_name']) {
                    $parentKey = mb_strtolower($row['parent_name']);

                    if (! isset($idByName[$parentKey])) {
                        $errors[] = ['row' => $rowNum, 'message' => "Parent category '{$row['parent_name']}' not found. List the parent before its children."];

                        continue;
                    }

                    $parentId = $idByName[$parentKey];
                }

                $preview[] = ['row' => $rowNum, 'name' => $name, 'action' => 'create'];

                // Reserve the name so a duplicate row later in the file is
                // treated as already-existing instead of creating a twin.
                $idByName[$key] = true;

                if ($dryRun) {
                    continue;
                }

                $category = ItemCategory::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'name' => $name,
                    'parent_id' => $parentId,
                    'is_active' => $row['is_active'] === null ? true : CsvParser::parseBool($row['is_active']),
                ]);

                $idByName[$key] = $category->id;
                $created++;
                $createdIds[] = $category->id;
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
