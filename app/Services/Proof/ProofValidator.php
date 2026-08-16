<?php

namespace App\Services\Proof;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\JournalEntry;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/**
 * Read-only checker that proves a {@see ProofScenario}'s books are internally
 * consistent. Every figure is recomputed through {@see ReportCalculator} — the
 * same engine the report pages use — so a passing result means the engine agrees
 * with itself across the trial balance, balance sheet, and income statement, and
 * that the immutable audit chain still verifies.
 *
 * The returned array is the single source of truth for both the test assertions
 * and the published `manifest.json`.
 */
class ProofValidator
{
    public function __construct(private readonly ReportCalculator $calculator) {}

    /**
     * @return array{
     *   key: string, title: string, company: array{id: int, name: string},
     *   passed: bool, audit: array{passed: bool, rows: int, detail: string},
     *   checkpoints: list<array<string, mixed>>
     * }
     */
    public function validate(ProofScenario $scenario): array
    {
        $checkpoints = [];
        foreach ($scenario->checkpoints as $checkpoint) {
            $checkpoints[] = $this->validateCheckpoint($scenario, $checkpoint['label'], $checkpoint['as_of']);
        }

        if ($scenario->tieOut !== null) {
            $checkpoints[] = $this->validateTieOut($scenario);
        }

        $audit = $this->validateAuditChain($scenario);

        $passed = $audit['passed'] && collect($checkpoints)->every(
            fn (array $cp) => collect($cp['checks'])->every(fn (array $c) => $c['passed'])
        );

        return [
            'key' => $scenario->key,
            'title' => $scenario->title,
            'company' => ['id' => $scenario->company->id, 'name' => $scenario->company->name],
            'passed' => $passed,
            'audit' => $audit,
            'checkpoints' => $checkpoints,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCheckpoint(ProofScenario $scenario, string $label, CarbonImmutable $asOf): array
    {
        $company = $scenario->company;
        $tb = $this->calculator->trialBalance($company, $asOf);

        $totalDebit = 0;
        $totalCredit = 0;
        $byType = [
            AccountType::Asset->value => 0,
            AccountType::Liability->value => 0,
            AccountType::Equity->value => 0,
            AccountType::Income->value => 0,
            AccountType::Expense->value => 0,
        ];

        foreach ($tb as $row) {
            /** @var Account $account */
            $account = $row['account'];
            $balance = $row['balance'];
            $byType[$account->type->value] += $balance;

            if ($account->normal_balance === NormalBalance::Debit) {
                $totalDebit += $balance > 0 ? $balance : 0;
                $totalCredit += $balance < 0 ? -$balance : 0;
            } else {
                $totalCredit += $balance > 0 ? $balance : 0;
                $totalDebit += $balance < 0 ? -$balance : 0;
            }
        }

        $assets = $byType[AccountType::Asset->value];
        $liabilities = $byType[AccountType::Liability->value];
        $equity = $byType[AccountType::Equity->value];
        $income = $byType[AccountType::Income->value];
        $expense = $byType[AccountType::Expense->value];
        $netIncome = $income - $expense;
        $netIncomeYtd = $this->calculator->netIncomeYtd($company, $asOf);

        $checks = [
            [
                'name' => 'Trial balance is balanced (debits = credits)',
                'passed' => $totalDebit === $totalCredit,
                'detail' => 'Debits '.$this->money($totalDebit).' = Credits '.$this->money($totalCredit),
            ],
            [
                'name' => 'Balance sheet balances (Assets = Liabilities + Equity + Net income)',
                'passed' => $assets === $liabilities + $equity + $netIncome,
                'detail' => $this->money($assets).' = '.$this->money($liabilities).' + '
                    .$this->money($equity).' + '.$this->money($netIncome),
            ],
            [
                'name' => 'Income statement net income ties to the trial balance',
                'passed' => $netIncome === $netIncomeYtd,
                'detail' => 'Trial balance net income '.$this->money($netIncome)
                    .' = income statement net income '.$this->money($netIncomeYtd),
            ],
        ];

        // Test 2: every imported figure must round-trip to the resulting balance.
        foreach ($scenario->importedRows as $importedRow) {
            $account = Account::query()
                ->where('company_id', $company->id)
                ->where('code', $importedRow['code'])
                ->first();

            $expected = $account === null
                ? 0
                : ($account->normal_balance === NormalBalance::Debit
                    ? $importedRow['debit'] - $importedRow['credit']
                    : $importedRow['credit'] - $importedRow['debit']);
            $actual = $account === null ? 0 : $this->calculator->balanceAsOf($account, $asOf);

            $checks[] = [
                'name' => "Imported balance for account {$importedRow['code']} matches the ledger",
                'passed' => $account !== null && $expected === $actual,
                'detail' => 'Imported '.$this->money($expected).' = ledger '.$this->money($actual),
            ];
        }

        return [
            'label' => $label,
            'as_of' => $asOf->toDateString(),
            'checks' => $checks,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equity,
                'income' => $income,
                'expense' => $expense,
                'net_income' => $netIncome,
            ],
        ];
    }

    /**
     * Tie every replayed account back to the source totals, and confirm the right
     * number of transactions posted. Used by the QuickBooks-journal scenario.
     *
     * @return array<string, mixed>
     */
    private function validateTieOut(ProofScenario $scenario): array
    {
        $tieOut = $scenario->tieOut;
        $asOf = $tieOut['as_of'];
        $checks = [];

        foreach ($tieOut['accounts'] as $code => $expected) {
            $account = Account::query()
                ->where('company_id', $scenario->company->id)
                ->where('code', (string) $code)
                ->first();

            $actual = $account === null ? 0 : $this->calculator->rawBalanceAsOf($account, $asOf);

            $checks[] = [
                'name' => "Account {$code} ties to the QuickBooks source",
                'passed' => $account !== null && $actual === $expected,
                'detail' => 'Source net '.$this->money($expected).' = ledger net '.$this->money($actual),
            ];
        }

        $posted = JournalEntry::query()
            ->where('company_id', $scenario->company->id)
            ->where('source_type', 'qbd_import')
            ->count();

        $checks[] = [
            'name' => 'Every QuickBooks transaction was replayed',
            'passed' => $posted === $tieOut['transactions'],
            'detail' => "{$posted} of {$tieOut['transactions']} transactions posted to the ledger",
        ];

        return [
            'label' => $tieOut['label'],
            'as_of' => $asOf->toDateString(),
            'checks' => $checks,
            'totals' => [],
        ];
    }

    /**
     * @return array{passed: bool, rows: int, detail: string}
     */
    private function validateAuditChain(ProofScenario $scenario): array
    {
        $rows = AccountingAuditLog::query()
            ->withoutGlobalScopes()
            ->where('company_id', $scenario->company->id)
            ->count();

        $exitCode = Artisan::call('audit:verify', ['company' => $scenario->company->id]);

        return [
            'passed' => $exitCode === 0,
            'rows' => $rows,
            'detail' => $exitCode === 0
                ? "Hash chain intact across {$rows} immutable audit rows"
                : 'Audit chain verification FAILED',
        ];
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
