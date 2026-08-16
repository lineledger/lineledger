<?php

namespace App\Support\Mcp;

use App\Enums\AccountType;
use App\Mcp\Resources\ChartOfAccountsResource;
use App\Mcp\Tools\ChartOfAccountsTool;
use App\Models\Account;
use App\Models\Company;
use App\Services\Reporting\ReportCalculator;
use App\Support\Gifi\GifiCatalog;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Renders a company's chart of accounts as plain text for the MCP server.
 * Shared by {@see ChartOfAccountsResource} and its companion
 * {@see ChartOfAccountsTool}. Each line carries the account code,
 * name, subtype, GIFI code + label, the numeric API id, and the QuickBooks-style
 * reporting balance — the same balance the chart-of-accounts page shows.
 *
 * The API id is spelled out because `code` and `id` are both numeric and are NOT
 * interchangeable: the REST API and integration configs key on `id` (an opaque
 * surrogate), while `code` is the user-facing account number a company can
 * renumber at will. Callers wiring up `/api/v1` need the id, so the line labels
 * it rather than leaving two bare numbers to be told apart by position.
 */
class ChartOfAccountsPresenter
{
    public function __construct(private ReportCalculator $calculator) {}

    public function render(Company $company): string
    {
        $asOf = $company->currentDateTime();

        $accounts = Account::query()->orderBy('code')->get();

        if ($accounts->isEmpty()) {
            return "{$company->name} has no chart of accounts.";
        }

        $lines = [
            "Chart of accounts for {$company->name} (balances as of {$asOf->toFormattedDateString()}, in {$company->currency_code}):",
            'Each line reads: code  name  (subtype, flags, API id)  balance. '.ApiIdNote::forWritable('account_id'),
            '',
        ];

        foreach (AccountType::cases() as $type) {
            $group = $accounts->filter(fn (Account $account): bool => $account->type === $type);

            if ($group->isEmpty()) {
                continue;
            }

            $lines[] = $type->label();
            foreach ($group as $account) {
                $lines[] = '  '.$this->line($company, $account, $asOf);
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    private function line(Company $company, Account $account, CarbonInterface $asOf): string
    {
        $meta = [$account->subtype->label()];

        if ($account->is_system) {
            $meta[] = 'system';
        }
        if (! $account->is_active) {
            $meta[] = 'inactive';
        }
        if (filled($account->gifi_code)) {
            $label = GifiCatalog::find($account->gifi_code)['label'] ?? null;
            $meta[] = $label !== null
                ? "GIFI {$account->gifi_code} {$label}"
                : "GIFI {$account->gifi_code}";
        }

        $meta[] = "API id {$account->id}";

        $balance = Money::fromCents(
            $this->calculator->reportingBalanceAsOf($company, $account, $asOf),
            $company->currency_code,
        )->format();

        return "{$account->code}  {$account->name}  (".implode(', ', $meta).")  {$balance}";
    }
}
