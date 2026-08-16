<?php

namespace App\Mcp\Tools;

use App\Enums\NormalBalance;
use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Account;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TrialBalanceTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Trial balance';

    protected string $description = 'A trial balance as of a given date: every account with a non-zero balance shown in either its debit or credit column, followed by total debits and total credits (which always equal each other in a balanced ledger). Accepts an optional "as_of" ISO date and defaults to the company\'s current date. This is a read-only report and all figures are in the company\'s home currency.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('accounting:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $asOf = $this->resolveAsOf($request);
        $calculator = app(ReportCalculator::class);

        $rows = $calculator->trialBalance($this->company(), $asOf);

        $totalDebits = 0;
        $totalCredits = 0;

        $lines = [];

        foreach ($rows as $row) {
            /** @var Account $account */
            $account = $row['account'];
            $balance = (int) $row['balance'];

            $isDebitColumn = $this->landsInDebitColumn($account->normal_balance, $balance);
            $amount = abs($balance);

            if ($isDebitColumn) {
                $totalDebits += $amount;
                $debit = $this->money($amount);
                $credit = '';
            } else {
                $totalCredits += $amount;
                $debit = '';
                $credit = $this->money($amount);
            }

            $label = trim("{$account->code} {$account->name}");
            $lines[] = "• {$label} — Debit: ".($debit !== '' ? $debit : '—').', Credit: '.($credit !== '' ? $credit : '—');
        }

        $out = [
            "Trial balance for {$this->company()->name} as of {$asOf->toFormattedDateString()}:",
            '',
        ];

        if ($lines === []) {
            $out[] = '(No accounts have a non-zero balance.)';
        } else {
            $out = array_merge($out, $lines);
        }

        $out[] = '';
        $out[] = "Total debits: {$this->money($totalDebits)}";
        $out[] = "Total credits: {$this->money($totalCredits)}";
        $out[] = $totalDebits === $totalCredits
            ? 'The trial balance is in balance (total debits equal total credits).'
            : 'Warning: total debits do not equal total credits.';

        return Response::text(implode("\n", $out));
    }

    /**
     * Decide whether a natural-balance signed value belongs in the debit column.
     * A positive balance sits on the account's normal side; a negative (contra)
     * balance flips to the opposite column.
     */
    private function landsInDebitColumn(NormalBalance $normalBalance, int $balance): bool
    {
        $normalIsDebit = $normalBalance === NormalBalance::Debit;

        return $balance >= 0 ? $normalIsDebit : ! $normalIsDebit;
    }

    /**
     * The reporting date: an explicit "as_of" ISO date or the company's current date.
     */
    private function resolveAsOf(Request $request): CarbonImmutable
    {
        $asOf = $request->get('as_of');

        if (is_string($asOf) && $asOf !== '') {
            return CarbonImmutable::parse($asOf)->startOfDay();
        }

        return CarbonImmutable::parse($this->company()->currentDateTime())->startOfDay();
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'as_of' => $schema->string()
                ->description('Optional reporting date (ISO date, e.g. 2026-05-31). Defaults to the company\'s current date.'),
        ];
    }
}
