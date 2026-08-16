<?php

namespace App\Services\Migration\Importers;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports custom accounts on top of the auto-seeded chart of accounts.
 *
 * Optional step in the wizard — most users skip and use the seeded chart.
 * Existing codes are left untouched; new codes are created.
 */
class ChartOfAccountsImporter implements CompanyCsvImporter, Importer
{
    public function __construct(protected CsvParser $parser) {}

    public function templateHeaders(): array
    {
        return ['code', 'name', 'subtype', 'parent_code', 'description'];
    }

    public function templateExampleRows(): array
    {
        return [
            ['code' => '6110', 'name' => 'Vehicle Expenses', 'subtype' => 'expense', 'parent_code' => '', 'description' => ''],
            ['code' => '4200', 'name' => 'Consulting Revenue', 'subtype' => 'income', 'parent_code' => '', 'description' => ''],
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

    /**
     * Preview an import for a company outside the migration wizard (e.g. the
     * Chart of Accounts page). Same dry-run as preview(), without a migration run.
     */
    public function previewForCompany(string $csvPath, Company $company): ImportResult
    {
        return $this->run($csvPath, $company, true);
    }

    /**
     * Commit an import for a company outside the migration wizard.
     */
    public function commitForCompany(string $csvPath, Company $company): ImportResult
    {
        return $this->run($csvPath, $company, false);
    }

    protected function run(string $csvPath, Company $company, bool $dryRun): ImportResult
    {
        try {
            $rows = $this->normalizedRows($csvPath);
        } catch (Throwable $e) {
            return new ImportResult(isDryRun: $dryRun, previewRows: [], errors: [['row' => 0, 'message' => $e->getMessage()]]);
        }

        $errors = [];
        $preview = [];
        $createdIds = [];
        $created = 0;
        $skipped = 0;

        $existingCodes = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->pluck('id', 'code');

        $parentLookup = $existingCodes->all();

        $validSubtypes = array_map(fn (AccountSubtype $s) => $s->value, AccountSubtype::cases());

        // Maps a code already claimed by an earlier row to that row's number, so a
        // second occurrence of the same code within the file is caught up front (in
        // preview) instead of blowing up the commit transaction on the unique index.
        $seenInFile = [];

        $runner = function () use ($rows, $company, &$errors, &$preview, &$createdIds, &$created, &$skipped, $dryRun, $validSubtypes, $existingCodes, &$parentLookup, &$seenInFile): void {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $code = $row['code'];
                $name = $row['name'];
                $subtype = $row['subtype'] ? strtolower($row['subtype']) : null;

                if (! $code || ! $name || ! $subtype) {
                    $errors[] = ['row' => $rowNum, 'message' => 'code, name and subtype are required.'];

                    continue;
                }

                if (! in_array($subtype, $validSubtypes, true)) {
                    $errors[] = ['row' => $rowNum, 'message' => "Unknown subtype '{$subtype}'. Valid values: ".implode(', ', $validSubtypes)];

                    continue;
                }

                if (isset($existingCodes[$code])) {
                    $skipped++;
                    $preview[] = ['row' => $rowNum, 'code' => $code, 'name' => $name, 'action' => 'skip (exists)'];

                    continue;
                }

                if (isset($seenInFile[$code])) {
                    $errors[] = ['row' => $rowNum, 'message' => "Duplicate code '{$code}' — already used on row {$seenInFile[$code]}. Account codes must be unique."];

                    continue;
                }

                $seenInFile[$code] = $rowNum;

                $preview[] = ['row' => $rowNum, 'code' => $code, 'name' => $name, 'action' => 'create'];

                if ($dryRun) {
                    continue;
                }

                $subtypeEnum = AccountSubtype::from($subtype);

                $parentId = null;
                if ($row['parent_code'] && isset($parentLookup[$row['parent_code']])) {
                    $parentId = $parentLookup[$row['parent_code']];
                }

                $account = Account::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'parent_id' => $parentId,
                    'code' => $code,
                    'name' => $name,
                    'type' => $subtypeEnum->type(),
                    'subtype' => $subtypeEnum,
                    'normal_balance' => $subtypeEnum->type()->normalBalance(),
                    'is_system' => false,
                    'is_active' => true,
                    'description' => $row['description'],
                ]);

                $parentLookup[$code] = $account->id;
                $created++;
                $createdIds[] = $account->id;
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

    /**
     * Read a QuickBooks "Account Listing" (or the native template) into account-type
     * hints keyed by lowercased code and lowercased name. The general-ledger replay
     * uses these to type accounts it auto-creates from a CSV export, which — unlike
     * IIF — carries no per-account type. Returns empty maps when the file can't be
     * parsed, so the replay simply falls back to its default subtype.
     *
     * @return array{byCode: array<string, AccountSubtype>, byName: array<string, AccountSubtype>}
     */
    public function typeHints(string $csvPath): array
    {
        try {
            $rows = $this->normalizedRows($csvPath);
        } catch (Throwable) {
            return ['byCode' => [], 'byName' => []];
        }

        $byCode = [];
        $byName = [];

        foreach ($rows as $row) {
            if ($row['subtype'] === null) {
                continue;
            }

            $subtype = AccountSubtype::tryFrom(strtolower($row['subtype']));

            if ($subtype === null) {
                continue;
            }

            if ($row['code'] !== null && $row['code'] !== '') {
                $byCode[mb_strtolower($row['code'])] = $subtype;
            }

            if ($row['name'] !== null && $row['name'] !== '') {
                $byName[mb_strtolower($row['name'])] = $subtype;
            }
        }

        return ['byCode' => $byCode, 'byName' => $byName];
    }

    /**
     * Read either the native template (code, name, subtype[, parent_code, description])
     * or a QuickBooks "Account Listing" export (Account, Type, Accnt. #, Description)
     * into a uniform row shape. Skips QuickBooks' BOM/preamble and empty padding columns.
     *
     * @return list<array{code: ?string, name: ?string, subtype: ?string, parent_code: ?string, description: ?string}>
     */
    protected function normalizedRows(string $csvPath): array
    {
        $handle = @fopen($csvPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file at: {$csvPath}");
        }

        try {
            $cols = null;
            $isQuickBooks = false;

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }

                $map = $this->headerMap($row);
                $native = isset($map['code'], $map['name'], $map['subtype']);
                $quickBooks = isset($map['account'], $map['type']);

                if ($native || $quickBooks) {
                    $cols = $map;
                    $isQuickBooks = ! $native && $quickBooks;
                    break;
                }
            }

            if ($cols === null) {
                throw new \RuntimeException('Could not find a header row. Use the template columns (code, name, subtype) or a QuickBooks "Account Listing" export (Account, Type, Accnt. #).');
            }

            $rows = [];

            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                if ($cells === [null] || $cells === false) {
                    continue;
                }

                $get = fn (string $key): ?string => $this->cell($cells, $cols, $key);

                if ($isQuickBooks) {
                    $label = $get('account');

                    if ($label === null) {
                        continue;
                    }

                    $parsed = $this->splitLabel($label);
                    $type = $get('type');

                    // QuickBooks allows accounts without a number (e.g. "Reconciliation
                    // Discrepancies"). Fall back to a name-derived code so the account is
                    // still created and the GL replay can match it by name.
                    $code = $get('accnt. #') ?? $get('accnt.#') ?? $get('account #') ?? $get('number') ?? $parsed['code'];

                    if ($code === null && $parsed['name'] !== '') {
                        $code = mb_substr($parsed['name'], 0, 20); // code column is varchar(20)
                    }

                    $subtype = $type !== null ? AccountSubtype::fromQuickBooksType($type) : null;

                    // QuickBooks types its Undeposited Funds account as "Other Current
                    // Asset"; recognise it by name so receipts/deposits can find it.
                    if ($subtype !== null && $parsed['name'] !== '' && str_contains(mb_strtolower($parsed['name']), 'undeposited')) {
                        $subtype = AccountSubtype::UndepositedFunds;
                    }

                    $row = [
                        'code' => $code,
                        'name' => $parsed['name'],
                        'subtype' => $subtype?->value,
                        'parent_code' => null,
                        'description' => $get('description'),
                    ];
                } else {
                    $row = [
                        'code' => $get('code'),
                        'name' => $get('name'),
                        'subtype' => $get('subtype'),
                        'parent_code' => $get('parent_code'),
                        'description' => $get('description'),
                    ];
                }

                if ($row['code'] === null && $row['name'] === null && $row['subtype'] === null) {
                    continue;
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, ?string>  $row
     * @return array<string, int>
     */
    protected function headerMap(array $row): array
    {
        $map = [];

        foreach ($row as $i => $cell) {
            $header = strtolower(trim($this->toUtf8((string) ($cell ?? ''))));

            if ($header !== '' && ! isset($map[$header])) {
                $map[$header] = $i;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, ?string>  $cells
     * @param  array<string, int>  $cols
     */
    protected function cell(array $cells, array $cols, string $key): ?string
    {
        if (! isset($cols[$key])) {
            return null;
        }

        $value = $cells[$cols[$key]] ?? null;
        $value = $value === null ? '' : trim($this->toUtf8((string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * Split a QuickBooks "NUMBER · NAME" label into code + name.
     *
     * @return array{code: ?string, name: string}
     */
    protected function splitLabel(string $label): array
    {
        $segment = trim($label);

        if (str_contains($segment, ':')) {
            $parts = explode(':', $segment);
            $segment = trim((string) end($parts));
        }

        if (str_contains($segment, '·')) {
            [$code, $name] = array_pad(explode('·', $segment, 2), 2, '');
            $code = trim($code);
            $name = trim($name);

            if ($code !== '' && $name !== '') {
                return ['code' => $code, 'name' => $name];
            }
        }

        return ['code' => null, 'name' => $segment];
    }

    /**
     * QuickBooks exports are usually Windows-1252; convert to UTF-8 and drop any BOM.
     */
    protected function toUtf8(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return ltrim($value, "\u{FEFF}");
    }
}
