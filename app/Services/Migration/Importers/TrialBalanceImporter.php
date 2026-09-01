<?php

namespace App\Services\Migration\Importers;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Final step: posts a single JournalEntry on the conversion date that brings
 * the remaining GL accounts to their QB balances. Rows targeting accounts
 * already populated from sub-ledgers (AR, AP, Inventory, Accumulated
 * Depreciation) are rejected — those should come from the earlier steps.
 *
 * The plug goes to Opening Balance Equity so the entry always balances.
 */
class TrialBalanceImporter implements Importer
{
    /** @var list<AccountSubtype> */
    protected const BLOCKED_SUBTYPES = [
        AccountSubtype::AccountsReceivable,
        AccountSubtype::AccountsPayable,
        AccountSubtype::Inventory,
    ];

    public function __construct(
        protected CsvParser $parser,
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
    ) {}

    public function templateHeaders(): array
    {
        return ['account_code', 'debit', 'credit'];
    }

    public function templateExampleRows(): array
    {
        return [
            ['account_code' => '1000', 'debit' => '12500.00', 'credit' => ''],
            ['account_code' => '1300', 'debit' => '1800.00', 'credit' => ''],
            ['account_code' => '2100', 'debit' => '', 'credit' => '3400.00'],
            ['account_code' => '3900', 'debit' => '', 'credit' => '32750.00'],
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
        $rows = $this->parser->parse($csvPath, ['account_code'], $this->templateHeaders());
        $errors = [];
        $preview = [];
        $createdIds = [];

        $obe = app(OpeningBalanceAccountResolver::class)->resolve((int) $ctx->company->id);

        if (! $obe) {
            return new ImportResult(
                isDryRun: $dryRun,
                previewRows: [],
                errors: [['row' => 0, 'message' => "Missing 'Opening Balance Equity' account."]],
            );
        }

        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $ctx->company->id)
            ->get(['id', 'code', 'name', 'subtype'])
            ->keyBy('code');

        $accepted = [];
        $totalDebit = 0;
        $totalCredit = 0;

        $blockedValues = array_map(fn (AccountSubtype $s) => $s->value, self::BLOCKED_SUBTYPES);

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $code = $row['account_code'];
            $debit = CsvParser::parseCents($row['debit']) ?? 0;
            $credit = CsvParser::parseCents($row['credit']) ?? 0;

            if (! $code) {
                $errors[] = ['row' => $rowNum, 'message' => 'account_code is required.'];

                continue;
            }

            if ($debit === 0 && $credit === 0) {
                // Zero-balance account — nothing to carry over. QuickBooks trial
                // balance exports list every account, so skip these silently
                // rather than failing the whole import.
                continue;
            }

            if ($debit !== 0 && $credit !== 0) {
                $errors[] = ['row' => $rowNum, 'message' => 'a row cannot have both debit and credit values.'];

                continue;
            }

            $account = $accounts->get($code);

            if (! $account) {
                $errors[] = ['row' => $rowNum, 'message' => "Account code '{$code}' not found."];

                continue;
            }

            if (in_array($account->subtype->value, $blockedValues, true)) {
                $errors[] = ['row' => $rowNum, 'message' => "Account '{$account->name}' is a {$account->subtype->label()} control account — its balance must come from sub-ledger imports (AR, AP, Inventory), not the trial balance."];

                continue;
            }

            // Accumulated Depreciation is a contra-asset under fixed_asset subtype.
            // We detect it by name since the schema doesn't have a dedicated subtype.
            if (str_contains(strtolower($account->name), 'accumulated depreciation')) {
                $errors[] = ['row' => $rowNum, 'message' => "Account '{$account->name}' should be populated via the Fixed Assets import, not the trial balance."];

                continue;
            }

            $accepted[] = ['account_id' => $account->id, 'code' => $code, 'name' => $account->name, 'debit' => $debit, 'credit' => $credit];
            $totalDebit += $debit;
            $totalCredit += $credit;

            $preview[] = [
                'row' => $rowNum,
                'code' => $code,
                'name' => $account->name,
                'debit' => $debit > 0 ? CsvParser::centsLabel($debit) : '',
                'credit' => $credit > 0 ? CsvParser::centsLabel($credit) : '',
            ];
        }

        $plug = $totalDebit - $totalCredit;

        if ($dryRun || $errors !== [] || $accepted === []) {
            return new ImportResult(
                isDryRun: $dryRun,
                previewRows: $preview,
                errors: $errors,
                createdIds: $createdIds,
                summary: [
                    'rows' => count($rows),
                    'accepted' => count($accepted),
                    'total_debit_cents' => $totalDebit,
                    'total_credit_cents' => $totalCredit,
                    'plug_cents' => $plug,
                ],
            );
        }

        try {
            DB::transaction(function () use ($accepted, $ctx, $obe, $plug, &$createdIds): void {
                $entry = JournalEntry::withoutGlobalScopes()->create([
                    'company_id' => $ctx->company->id,
                    'entry_no' => $this->entryNumbers->next($ctx->company),
                    'entry_date' => $ctx->conversionDate->toDateString(),
                    'memo' => 'Opening trial balance — carried over from QuickBooks',
                ]);

                $order = 0;

                foreach ($accepted as $row) {
                    $entry->lines()->create([
                        'account_id' => $row['account_id'],
                        'debit_cents' => $row['debit'],
                        'credit_cents' => $row['credit'],
                        'memo' => "TB: {$row['code']} — {$row['name']}",
                        'line_order' => $order++,
                    ]);
                }

                if ($plug !== 0) {
                    $entry->lines()->create([
                        'account_id' => $obe->id,
                        'debit_cents' => $plug < 0 ? abs($plug) : 0,
                        'credit_cents' => $plug > 0 ? $plug : 0,
                        'memo' => 'Opening Balance Equity (plug to balance TB)',
                        'line_order' => $order++,
                    ]);
                }

                $entry->refresh();
                $this->journalPoster->post($entry);

                $createdIds[] = $entry->id;
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
                'total_debit_cents' => $totalDebit,
                'total_credit_cents' => $totalCredit,
                'plug_cents' => $plug,
            ],
        );
    }
}
