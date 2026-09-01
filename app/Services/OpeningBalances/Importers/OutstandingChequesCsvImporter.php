<?php

namespace App\Services\OpeningBalances\Importers;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\OpeningBalanceState;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\ImportResult;
use App\Services\OpeningBalances\OpeningBalanceJournalSynchronizer;
use App\Services\OpeningBalances\OutstandingChequeSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports the cheques that were written in the previous system and had not
 * cleared the bank at the conversion date. Rows whose (bank, cheque number)
 * already exist are skipped, so the QuickBooks outstanding-cheques report can
 * be re-imported as it shrinks. One apply at the end re-nets the bank.
 */
class OutstandingChequesCsvImporter implements CompanyCsvImporter
{
    public function __construct(
        protected CsvParser $parser,
        protected OutstandingChequeSync $sync,
        protected OpeningBalanceJournalSynchronizer $synchronizer,
    ) {}

    public function templateHeaders(): array
    {
        return ['bank_account_code', 'cheque_no', 'cheque_date', 'payee_name', 'amount', 'memo'];
    }

    public function templateExampleRows(): array
    {
        return [[
            'bank_account_code' => '1000',
            'cheque_no' => '4021',
            'cheque_date' => '2026-05-14',
            'payee_name' => 'Acme Roofing',
            'amount' => '200.00',
            'memo' => 'Outstanding at conversion',
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
            $rows = $this->parser->parse($csvPath, ['bank_account_code', 'cheque_no', 'payee_name', 'amount'], $this->templateHeaders());
        } catch (Throwable $e) {
            return new ImportResult($dryRun, [], [['row' => 0, 'message' => $e->getMessage()]]);
        }

        $errors = [];
        $preview = [];
        $accepted = [];
        $created = 0;
        $skipped = 0;

        $banks = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::Bank->value)
            ->get()
            ->keyBy('code');

        $existing = Cheque::withoutGlobalScopes()->withTrashed()
            ->where('company_id', $company->id)
            ->get(['bank_account_id', 'cheque_no'])
            ->map(fn ($c) => $c->bank_account_id.':'.$c->cheque_no)
            ->flip();

        $seen = [];

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
                $date = $row['cheque_date'] ? CarbonImmutable::parse($row['cheque_date']) : null;
            } catch (Throwable) {
                // handled below
            }

            if (! $date) {
                $errors[] = ['row' => $rowNum, 'message' => 'cheque_date is required (YYYY-MM-DD).'];

                continue;
            }

            $key = $bank->id.':'.$row['cheque_no'];

            if (isset($existing[$key]) || isset($seen[$key])) {
                $skipped++;
                $preview[] = [
                    'row' => $rowNum,
                    'name' => "#{$row['cheque_no']} — {$row['payee_name']}",
                    'action' => 'skip (exists)',
                    'amount' => CsvParser::centsLabel($amount),
                ];

                continue;
            }

            $seen[$key] = true;

            $accepted[] = [
                'bank_account_id' => (int) $bank->id,
                'cheque_no' => $row['cheque_no'],
                'cheque_date' => $date,
                'payee_name' => $row['payee_name'],
                'amount_cents' => $amount,
                'memo' => $row['memo'],
            ];

            $preview[] = [
                'row' => $rowNum,
                'name' => "#{$row['cheque_no']} — {$row['payee_name']}",
                'action' => 'create',
                'amount' => CsvParser::centsLabel($amount),
            ];
        }

        $summary = [
            'rows' => count($rows),
            'created' => count($accepted),
            'skipped_existing' => $skipped,
            'total_cents' => array_sum(array_column($accepted, 'amount_cents')),
        ];

        if ($dryRun || $errors !== [] || $accepted === []) {
            return new ImportResult($dryRun, $preview, $errors, [], $summary);
        }

        try {
            DB::transaction(function () use ($state, $accepted, &$created): void {
                foreach ($accepted as $data) {
                    $this->sync->create($state, $data, apply: false);
                    $created++;
                }
            });

            $this->synchronizer->applyQuietly($state->refresh());
        } catch (Throwable $e) {
            $errors[] = ['row' => 0, 'message' => 'Import aborted: '.$e->getMessage()];
        }

        return new ImportResult($dryRun, $preview, $errors, [], $summary);
    }
}
