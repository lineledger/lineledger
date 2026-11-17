<?php

namespace App\Services\Migration\Importers;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use App\Services\Migration\QuickBooksAccountLabel;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports custom accounts on top of the auto-seeded chart of accounts.
 *
 * Optional step in the wizard — most users skip and use the seeded chart.
 * Existing codes are left untouched; new codes are created. A row's parent must
 * already be in the chart or listed above it in the file, and be the same
 * account type; QuickBooks sub-accounts ("Parent:Sub") find their parent by path.
 */
class ChartOfAccountsImporter implements CompanyCsvImporter, Importer
{
    /** The accounts.code column is varchar(20). */
    private const CODE_LENGTH = 20;

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

        $existingAccounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('id')
            ->get(['id', 'parent_id', 'code', 'name', 'type']);

        $existingCodes = $existingAccounts->pluck('id', 'code');
        $existingPaths = QuickBooksAccountLabel::pathsOf($existingAccounts);

        // Where a later row can go: the chart as it stands, plus each row this run
        // accepts (with no id yet in a preview, so a preview places children under
        // parents that are only about to be created). The template names a parent
        // by code, QuickBooks by path.
        /** @var array<array-key, array{id: ?int, code: string, type: AccountType, label: string}> $byCode */
        $byCode = [];
        /** @var array<string, array{id: ?int, code: string, type: AccountType, label: string}> $byPath */
        $byPath = [];

        // Accounts an earlier import made for numberless QuickBooks accounts, by
        // path. Their code is made from their name, so a code can't identify them:
        // it depends on which other accounts shared the name in that file.
        /** @var array<string, array{id: ?int, code: string, type: AccountType, label: string}> $importedByPath */
        $importedByPath = [];

        // Lower-cased codes a numberless account can't take (MySQL's unique index on
        // accounts.code ignores case).
        $taken = [];

        foreach ($existingAccounts as $account) {
            $path = $existingPaths[$account->id];
            $entry = ['id' => $account->id, 'code' => (string) $account->code, 'type' => $account->type, 'label' => $path];
            $byCode[$account->code] = $entry;
            $byPath[QuickBooksAccountLabel::keyFor($path)] = $entry;
            $taken[mb_strtolower((string) $account->code)] = true;

            if ($this->isCodeFromName((string) $account->code, $account->name)) {
                $importedByPath[QuickBooksAccountLabel::keyFor($path)] = $entry;
            }
        }

        // Every code and QuickBooks path the file uses, to tell a parent listed below
        // its child apart from one that is missing altogether.
        $codesInFile = [];
        $pathsInFile = [];

        foreach ($rows as $row) {
            if ($row['code'] !== null) {
                $codesInFile[$row['code']] = true;
                $taken[mb_strtolower($row['code'])] = true;
            }

            if ($row['path'] !== null) {
                $pathsInFile[QuickBooksAccountLabel::keyFor($row['path'])] = true;
            }
        }

        $validSubtypes = array_map(fn (AccountSubtype $s) => $s->value, AccountSubtype::cases());

        // Maps a code already claimed by an earlier row to that row's number, so a
        // second occurrence of the same code within the file is caught up front (in
        // preview) instead of blowing up the commit transaction on the unique index.
        $seenInFile = [];

        // Codes and paths of the rows read so far, accepted or not.
        $processedCodes = [];
        $processedPaths = [];

        $runner = function () use ($rows, $company, &$errors, &$preview, &$createdIds, &$created, &$skipped, $dryRun, $validSubtypes, $existingCodes, &$byCode, &$byPath, $importedByPath, &$taken, $codesInFile, $pathsInFile, &$seenInFile, &$processedCodes, &$processedPaths): void {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $code = $row['code'];
                $name = $row['name'];
                $subtype = $row['subtype'] ? strtolower($row['subtype']) : null;
                $pathKey = $row['path'] !== null ? QuickBooksAccountLabel::keyFor($row['path']) : null;

                if ($code !== null) {
                    $processedCodes[$code] = true;
                }

                if ($pathKey !== null) {
                    $processedPaths[$pathKey] = true;
                }

                // A QuickBooks account may have no number; it gets a code below.
                if (($code === null && $pathKey === null) || ! $name || ! $subtype) {
                    $errors[] = ['row' => $rowNum, 'message' => 'code, name and subtype are required.'];

                    continue;
                }

                if (! in_array($subtype, $validSubtypes, true)) {
                    $errors[] = ['row' => $rowNum, 'message' => "Unknown subtype '{$subtype}'. Valid values: ".implode(', ', $validSubtypes)];

                    continue;
                }

                $subtypeEnum = AccountSubtype::from($subtype);

                // Every preview row carries the same keys in the same order: the
                // migration wizard builds its table header from the first row.
                if ($code === null) {
                    // A numberless QuickBooks account is the one an earlier import
                    // made at the same path; otherwise it is new and gets its name,
                    // cut to the code column, with "-2", "-3"… if that is taken.
                    if (isset($importedByPath[$pathKey])) {
                        $existing = $importedByPath[$pathKey];
                        $byPath[$pathKey] = $existing;
                        $skipped++;
                        $preview[] = ['row' => $rowNum, 'code' => $existing['code'], 'name' => $name, 'parent' => null, 'action' => 'skip (exists)'];

                        continue;
                    }

                    $code = $this->unusedCode($name, $taken);
                    $taken[mb_strtolower($code)] = true;
                } elseif (isset($existingCodes[$code])) {
                    if ($pathKey !== null) {
                        $byPath[$pathKey] = $byCode[$code];
                    }

                    $skipped++;
                    $preview[] = ['row' => $rowNum, 'code' => $code, 'name' => $name, 'parent' => null, 'action' => 'skip (exists)'];

                    continue;
                }

                if (isset($seenInFile[$code])) {
                    $errors[] = ['row' => $rowNum, 'message' => "Duplicate code '{$code}' — already used on row {$seenInFile[$code]}. Account codes must be unique."];

                    continue;
                }

                $seenInFile[$code] = $rowNum;

                $parent = null;
                $parentError = null;

                if ($row['parent_label'] !== null) {
                    // A QuickBooks sub-account: its parent is the account at the path
                    // above it, or, failing that, the parent segment's own number.
                    $parentKey = QuickBooksAccountLabel::keyFor($row['parent_label']);
                    $parent = $byPath[$parentKey]
                        ?? ($row['parent_number'] !== null ? ($byCode[$row['parent_number']] ?? null) : null);
                    $parentError = $this->parentError($parent, $row['parent_label'], isset($processedPaths[$parentKey]), isset($pathsInFile[$parentKey]));
                } elseif ($row['parent_code'] !== null) {
                    $parentCode = $row['parent_code'];

                    if ($parentCode === $code) {
                        $parentError = __('An account cannot be its own parent.');
                    } else {
                        $parent = $byCode[$parentCode] ?? null;
                        $parentError = $this->parentError($parent, $parentCode, isset($processedCodes[$parentCode]), isset($codesInFile[$parentCode]));
                    }
                }

                if ($parent !== null && $parentError === null && $parent['type'] !== $subtypeEnum->type()) {
                    $parentError = __('The parent account must be the same account type.');
                }

                if ($parentError !== null) {
                    $errors[] = ['row' => $rowNum, 'message' => $parentError];

                    continue;
                }

                $preview[] = ['row' => $rowNum, 'code' => $code, 'name' => $name, 'parent' => $parent['label'] ?? null, 'action' => 'create'];

                $entry = ['id' => null, 'code' => $code, 'type' => $subtypeEnum->type(), 'label' => $row['path'] ?? $name];

                if (! $dryRun) {
                    $account = Account::withoutGlobalScopes()->create([
                        'company_id' => $company->id,
                        'parent_id' => $parent['id'] ?? null,
                        'code' => $code,
                        'name' => $name,
                        'type' => $subtypeEnum->type(),
                        'subtype' => $subtypeEnum,
                        'normal_balance' => $subtypeEnum->type()->normalBalance(),
                        'is_system' => false,
                        'is_active' => true,
                        'description' => $row['description'],
                    ]);

                    $entry['id'] = $account->id;
                    $created++;
                    $createdIds[] = $account->id;
                }

                $byCode[$code] = $entry;

                if ($pathKey !== null) {
                    $byPath[$pathKey] = $entry;
                }
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

            // A sub-account's full path tells apart two accounts that share a name.
            if ($row['path'] !== null && $row['path'] !== '') {
                $byName[QuickBooksAccountLabel::keyFor($row['path'])] = $subtype;
            }
        }

        return ['byCode' => $byCode, 'byName' => $byName];
    }

    /**
     * Read either the native template (code, name, subtype[, parent_code, description])
     * or a QuickBooks "Account Listing" export (Account, Type, Accnt. #, Description)
     * into a uniform row shape. Skips QuickBooks' BOM/preamble and empty padding columns.
     *
     * A QuickBooks row also carries its `path` ("Pass Through Expense:Hosting"), its
     * parent's (`parent_label`) and the parent segment's own number, if it has one
     * (`parent_number`); `code` is null when the account has no number. The native
     * template sets those to null and takes `parent_code` as given.
     *
     * @return list<array{code: ?string, name: ?string, subtype: ?string, parent_code: ?string, parent_label: ?string, parent_number: ?string, path: ?string, description: ?string}>
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

                    $segments = QuickBooksAccountLabel::segments($label);
                    $parsed = $segments[array_key_last($segments)];
                    $type = $get('type');

                    // An account without a number (e.g. "Reconciliation Discrepancies")
                    // stays null here; run() gives it a code made from its name.
                    $code = $get('accnt. #') ?? $get('accnt.#') ?? $get('account #') ?? $get('number') ?? $parsed['code'];

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
                        'parent_label' => QuickBooksAccountLabel::parentDisplayPath($label),
                        'parent_number' => count($segments) > 1 ? $segments[count($segments) - 2]['code'] : null,
                        'path' => QuickBooksAccountLabel::displayPath($label),
                        'description' => $get('description'),
                    ];
                } else {
                    $row = [
                        'code' => $get('code'),
                        'name' => $get('name'),
                        'subtype' => $get('subtype'),
                        'parent_code' => $get('parent_code'),
                        'parent_label' => null,
                        'parent_number' => null,
                        'path' => null,
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
     * Why a row can't go under its parent, or null when it can. $parent is what the
     * code or path found; the flags say whether the file has the parent at all,
     * and whether its row has been read yet.
     *
     * @param  array{id: ?int, code: string, type: AccountType, label: string}|null  $parent
     */
    protected function parentError(?array $parent, string $parentName, bool $alreadyRead, bool $inFile): ?string
    {
        return match (true) {
            $parent !== null => null,
            $alreadyRead => __("Parent account ':parent' has an error of its own, so this row cannot go under it.", ['parent' => $parentName]),
            $inFile => __("Parent account ':parent' must be listed above this row.", ['parent' => $parentName]),
            default => __("Parent account ':parent' is not in this file or the chart of accounts.", ['parent' => $parentName]),
        };
    }

    /**
     * Whether a code is one unusedCode() would make from this name: the name cut to
     * the code column, or that with "-2", "-3"… The accounts with such codes are the
     * ones an import made for numberless QuickBooks accounts. A seeded or numbered
     * account never matches, so a QuickBooks account is not merged into one that
     * merely shares its name.
     */
    protected function isCodeFromName(string $code, string $name): bool
    {
        $base = mb_strtolower(mb_substr($name, 0, self::CODE_LENGTH));
        $code = mb_strtolower($code);

        if ($code === $base) {
            return true;
        }

        if (preg_match('/^(.*)-(\d+)$/su', $code, $m) !== 1 || (int) $m[2] < 2) {
            return false;
        }

        return $m[1] === rtrim(mb_substr($base, 0, self::CODE_LENGTH - mb_strlen('-'.$m[2])));
    }

    /**
     * The name cut to the code column, with "-2", "-3"… when that is taken.
     *
     * @param  array<string, true>  $taken  lower-cased codes already in use
     */
    protected function unusedCode(string $name, array $taken): string
    {
        $base = mb_substr($name, 0, self::CODE_LENGTH);
        $code = $base;

        for ($n = 2; isset($taken[mb_strtolower($code)]); $n++) {
            $suffix = '-'.$n;
            $code = rtrim(mb_substr($base, 0, self::CODE_LENGTH - mb_strlen($suffix))).$suffix;
        }

        return $code;
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
