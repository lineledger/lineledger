<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Account;
use App\Services\Reporting\ReportCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AccountBalanceTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Account Balance & Activity';

    protected string $description = 'Look up the balance and recent ledger activity for a single account, matched by name or code. Provide an "account" name (partial match) or exact account code. Optionally pass a "period" (this_month, last_month, this_quarter, last_quarter, this_year, last_year, ytd) or explicit "start"/"end" ISO dates to set the activity window; the balance is shown as of the end of that window. All figures are in the company\'s home currency. This tool is read-only and never modifies any data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('accounting:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Accounting)) {
            return $denied;
        }

        $query = trim((string) $request->get('account', ''));

        if ($query === '') {
            return Response::text('Please provide an "account" name or code to look up.');
        }

        $matches = Account::query()
            ->where(function ($where) use ($query) {
                $where->where('code', $query)
                    ->orWhere('name', 'like', "%{$query}%");
            })
            ->orderBy('code')
            ->get();

        if ($matches->isEmpty()) {
            return Response::text("No account matched \"{$query}\". Try a different name or the account code.");
        }

        if ($matches->count() > 1) {
            $list = $matches
                ->map(fn (Account $account): string => "- {$account->code} — {$account->name} ({$account->type->label()})")
                ->implode("\n");

            return Response::text(
                "Several accounts match \"{$query}\". Please specify the exact account code:\n\n".$list
            );
        }

        /** @var Account $account */
        $account = $matches->first();

        $period = $this->resolvePeriod($request);

        $calculator = app(ReportCalculator::class);

        $balance = $calculator->balanceAsOf($account, $period['end']);
        $ledger = $calculator->generalLedger($account, $period['start'], $period['end']);

        $recent = collect($ledger['lines'])->reverse()->take(10);

        $lines = [];
        $lines[] = "Account: {$account->code} — {$account->name} ({$account->type->label()})";
        $lines[] = "Balance as of {$period['end']->toDateString()}: ".$this->money($balance);
        $lines[] = "Activity window: {$period['label']} ({$period['start']->toDateString()} to {$period['end']->toDateString()})";
        $lines[] = 'Opening balance: '.$this->money($ledger['opening']);
        $lines[] = 'Closing balance: '.$this->money($ledger['closing']);
        $lines[] = '';

        if ($recent->isEmpty()) {
            $lines[] = 'No ledger activity in this period.';
        } else {
            $lines[] = 'Recent ledger lines (most recent first):';

            foreach ($recent as $line) {
                $memo = $line['memo'] !== null && $line['memo'] !== '' ? " — {$line['memo']}" : '';
                $debit = $this->money($line['debit']);
                $credit = $this->money($line['credit']);
                $running = $this->money($line['running']);
                $lines[] = "- {$line['date']} {$line['entry_no']}: debit {$debit}, credit {$credit}, running {$running}{$memo}";
            }
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()
                ->description('The account to look up: a name (partial match) or an exact account code, e.g. "Chequing" or "1000".'),
            'period' => $schema->string()
                ->description('Optional named activity window: this_month, last_month, this_quarter, last_quarter, this_year, last_year, or ytd (default).'),
            'start' => $schema->string()
                ->description('Optional explicit window start date (ISO, YYYY-MM-DD). Overrides "period" when paired with "end".'),
            'end' => $schema->string()
                ->description('Optional explicit window end date (ISO, YYYY-MM-DD). The balance is shown as of this date.'),
        ];
    }
}
