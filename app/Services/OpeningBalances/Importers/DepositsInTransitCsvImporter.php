<?php

namespace App\Services\OpeningBalances\Importers;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\OpeningBalanceState;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\ImportResult;
use App\Services\OpeningBalances\DepositInTransitSync;
use App\Services\OpeningBalances\OpeningBalanceJournalSynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports deposits recorded in the previous system that had not reached a
 * bank statement at the conversion date. Deposits get generated OBD numbers,
 * so unlike cheques there is no natural key — import this file once.
 */
class DepositsInTransitCsvImporter implements CompanyCsvImporter
{
    public function __construct(
        protected CsvParser $parser,
        protected DepositInTransitSync $sync,
        protected OpeningBalanceJournalSynchronizer $synchronizer,
    ) {}

    public function templateHeaders(): array
    {
        return ['bank_account_code', 'deposit_date', 'description', 'amount', 'memo'];
    }

    public function templateExampleRows(): array
    {
        return [[
            'bank_account_code' => '1000',
            'deposit_date' => '2026-06-29',
            'description' => 'June 29 daily takings',
            'amount' => '50.00',
            'memo' => 'In transit at conversion',
        ]];
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
            $rows = $this->parser->parse($csvPath, ['bank_account_code', 'deposit_date', 'amount'], $this->templateHeaders());
        } catch (Throwable $e) {
            return new ImportResult($dryRun, [], [['row' => 0, 'message' => $e->getMessage()]]);
        }

        $errors = [];
        $preview = [];
        $accepted = [];

        $banks = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::Bank->value)
            ->get()
            ->keyBy('code');

        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;
            $bank = $banks->get($row['bank_account_code']);
            $amount = CsvParser::parseCents($row['amount']);

            if (! $bank) {
                $errors[] = ['row' => $rowNum, 'message' => "Bank account code '{$row['bank_account_code']}' not found (must be a bank-subtype account)."];

                continue;
            }

            if ($bank->currency_code !== null && ! $company->isHomeCurrency($bank->currency_code)) {
                $errors[] = ['row' => $rowNum, 'message' => "Bank '{$bank->name}' is a foreign-currency account — record its opening items with a journal entry instead."];

                continue;
            }

            if ($amount === null || $amount <= 0) {
                $errors[] = ['row' => $rowNum, 'message' => 'amount must be greater than zero.'];

                continue;
            }

            $date = null;

            try {
                $date = $row['deposit_date'] ? CarbonImmutable::parse($row['deposit_date']) : null;
            } catch (Throwable) {
                // handled below
            }

            if (! $date) {
                $errors[] = ['row' => $rowNum, 'message' => 'deposit_date is required (YYYY-MM-DD).'];

                continue;
            }

            $accepted[] = [
                'bank_account_id' => (int) $bank->id,
                'deposit_date' => $date,
                'description' => $row['description'],
                'amount_cents' => $amount,
                'memo' => $row['memo'],
            ];

            $preview[] = [
                'row' => $rowNum,
                'name' => trim(($row['description'] ?? '') !== '' ? $row['description'] : 'Deposit').' — '.$date->toDateString(),
                'action' => 'create',
                'amount' => CsvParser::centsLabel($amount),
            ];
        }

        $summary = [
            'rows' => count($rows),
            'created' => count($accepted),
            'total_cents' => array_sum(array_column($accepted, 'amount_cents')),
        ];

        if ($dryRun || $errors !== [] || $accepted === []) {
            return new ImportResult($dryRun, $preview, $errors, [], $summary);
        }

        try {
            DB::transaction(function () use ($state, $accepted): void {
                foreach ($accepted as $data) {
                    $this->sync->create($state, $data, apply: false);
                }
            });

            $this->synchronizer->applyQuietly($state->refresh());
        } catch (Throwable $e) {
            $errors[] = ['row' => 0, 'message' => 'Import aborted: '.$e->getMessage()];
        }

        return new ImportResult($dryRun, $preview, $errors, [], $summary);
    }
}
