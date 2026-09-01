<?php

namespace App\Services\OpeningBalances\Importers;

use App\Models\Account;
use App\Models\Company;
use App\Models\OpeningBalanceRow;
use App\Models\OpeningBalanceState;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\ImportResult;
use App\Services\OpeningBalances\OpeningBalanceJournalSynchronizer;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Seeds / replaces the workspace's draft trial balance TARGETS from a
 * QuickBooks trial balance export (same columns as the wizard's
 * TrialBalanceImporter). Unlike the wizard this never posts directly: it
 * upserts opening_balance_rows and then runs one apply, so re-importing a
 * corrected export is the normal workflow, not an error.
 *
 * AR / AP / Inventory / OBE rows are ACCEPTED here — they become targets the
 * status panel reconciles against sub-ledger detail; the synchronizer simply
 * never writes journal lines for them.
 */
class OpeningTrialBalanceCsvImporter implements CompanyCsvImporter
{
    public function __construct(
        protected CsvParser $parser,
        protected OpeningBalanceJournalSynchronizer $synchronizer,
    ) {}

    public function templateHeaders(): array
    {
        return ['account_code', 'debit', 'credit'];
    }

    public function templateExampleRows(): array
    {
        return [
            ['account_code' => '1000', 'debit' => '12500.00', 'credit' => ''],
            ['account_code' => '1100', 'debit' => '4200.00', 'credit' => ''],
            ['account_code' => '1300', 'debit' => '1800.00', 'credit' => ''],
            ['account_code' => '2000', 'debit' => '', 'credit' => '2350.00'],
            ['account_code' => '3900', 'debit' => '', 'credit' => '16150.00'],
        ];
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
        $state = OpeningBalanceState::for($company);

        if (! $state) {
            return new ImportResult($dryRun, [], [['row' => 0, 'message' => 'Open the Opening Balances workspace first.']]);
        }

        try {
            $rows = $this->parser->parse($csvPath, ['account_code'], $this->templateHeaders());
        } catch (Throwable $e) {
            return new ImportResult($dryRun, [], [['row' => 0, 'message' => $e->getMessage()]]);
        }

        $errors = [];
        $preview = [];
        $accepted = [];
        $totalDebit = 0;
        $totalCredit = 0;

        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get(['id', 'code', 'name'])
            ->keyBy('code');

        $existing = $state->rows()->get()->keyBy('account_id');

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
                // QuickBooks exports list every account; zero rows carry nothing.
                continue;
            }

            if ($debit !== 0 && $credit !== 0) {
                $errors[] = ['row' => $rowNum, 'message' => 'a row cannot have both debit and credit values.'];

                continue;
            }

            if ($debit < 0 || $credit < 0) {
                $errors[] = ['row' => $rowNum, 'message' => 'negative amounts are not allowed — put the value in the other column.'];

                continue;
            }

            $account = $accounts->get($code);

            if (! $account) {
                $errors[] = ['row' => $rowNum, 'message' => "Account code '{$code}' not found."];

                continue;
            }

            if (isset($accepted[$account->id])) {
                $errors[] = ['row' => $rowNum, 'message' => "Account code '{$code}' appears more than once."];

                continue;
            }

            $accepted[$account->id] = ['debit' => $debit, 'credit' => $credit];
            $totalDebit += $debit;
            $totalCredit += $credit;

            $current = $existing->get($account->id);
            $action = $current === null
                ? 'create'
                : (((int) $current->debit_cents === $debit && (int) $current->credit_cents === $credit) ? 'unchanged' : 'update');

            $preview[] = [
                'row' => $rowNum,
                'name' => "{$code} — {$account->name}",
                'action' => $action,
                'debit' => $debit > 0 ? CsvParser::centsLabel($debit) : '',
                'credit' => $credit > 0 ? CsvParser::centsLabel($credit) : '',
            ];
        }

        // Targets present in the workspace but absent from the file are removed —
        // the import is a full replacement of the draft.
        $removed = $existing->keys()->diff(array_keys($accepted));

        foreach ($removed as $accountId) {
            $account = $accounts->firstWhere('id', $accountId);
            $preview[] = [
                'row' => 0,
                'name' => $account ? "{$account->code} — {$account->name}" : "Account #{$accountId}",
                'action' => 'remove',
                'debit' => '',
                'credit' => '',
            ];
        }

        $summary = [
            'rows' => count($rows),
            'created' => collect($preview)->where('action', 'create')->count(),
            'updated' => collect($preview)->where('action', 'update')->count(),
            'removed' => $removed->count(),
            'total_debit_cents' => $totalDebit,
            'total_credit_cents' => $totalCredit,
            'imbalance_cents' => $totalDebit - $totalCredit,
        ];

        if ($dryRun || $errors !== []) {
            return new ImportResult($dryRun, $preview, $errors, [], $summary);
        }

        try {
            DB::transaction(function () use ($state, $accepted, $removed): void {
                foreach ($accepted as $accountId => $amounts) {
                    OpeningBalanceRow::withoutGlobalScopes()->updateOrCreate(
                        ['opening_balance_state_id' => $state->id, 'account_id' => $accountId],
                        [
                            'company_id' => $state->company_id,
                            'debit_cents' => $amounts['debit'],
                            'credit_cents' => $amounts['credit'],
                            'updated_by_user_id' => auth()->id(),
                        ],
                    );
                }

                if ($removed->isNotEmpty()) {
                    $state->rows()->whereIn('account_id', $removed)->delete();
                }
            });

            // One apply for the whole file — auto-apply-on-save, import edition.
            $this->synchronizer->applyQuietly($state->refresh());
        } catch (Throwable $e) {
            $errors[] = ['row' => 0, 'message' => 'Import aborted: '.$e->getMessage()];
        }

        return new ImportResult($dryRun, $preview, $errors, [], $summary);
    }
}
