<?php

namespace App\Mcp\Tools;

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Services\Reporting\ReportCalculator;
use App\Support\Access\PayrollDetail;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AccountBalanceTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Account Balance & Activity';

    protected string $description = 'Look up the balance and ledger activity for a single account, matched by name or code. Provide an "account" name (partial match) or exact account code. Optionally pass a "period" (this_month, last_month, this_quarter, last_quarter, this_year, last_year, ytd) or explicit "start"/"end" ISO dates to set the activity window; the balance is shown as of the end of that window. Lines are listed most recent first, 10 at a time by default: use "limit" (up to 500) and "offset" to page, and narrow with "contact" or "reference" (an entry number, or text such as a document number found in the memo). To see what is on an invoice, use get-invoice-tool rather than reading account ledgers. All figures are in the company\'s home currency. This tool is read-only and never modifies any data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('accounting:read')) {
            return $denied;
        }

        if ($denied = $this->requirePermission(Activity::Accounts, PermissionAction::ViewBalance)) {
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

        $all = collect($ledger['lines'])->reverse()->values();
        $payrollEntries = $this->actorSeesPayrollDetail() ? [] : $this->payrollEntryNumbers($all->pluck('entry_no')->all());

        $contact = null;
        $contactNeedle = trim((string) $request->get('contact', ''));
        if ($contactNeedle !== '') {
            $contact = $this->findContact($contactNeedle);
            if (is_string($contact)) {
                return Response::text($contact);
            }
        }

        $reference = mb_strtolower(trim((string) $request->get('reference', '')));

        // A payroll line hidden from this connection (PayrollDetail) matches
        // on its entry number only: matching its memo or employee would tell
        // the caller whose pay it is.
        $filtered = $all->filter(function (array $line) use ($contact, $reference, $payrollEntries): bool {
            $hidden = in_array($line['entry_no'], $payrollEntries, true);

            if ($contact !== null && ($hidden || $line['contact_id'] !== $contact->id)) {
                return false;
            }

            if ($reference === '') {
                return true;
            }

            if (mb_strtolower((string) $line['entry_no']) === $reference) {
                return true;
            }

            return ! $hidden && (str_contains(mb_strtolower((string) $line['memo']), $reference)
                || str_contains(mb_strtolower((string) $line['entry_memo']), $reference));
        })->values();

        $limit = max(1, min(500, (int) ($request->get('limit') ?? 10)));
        $offset = max(0, (int) ($request->get('offset') ?? 0));
        $recent = $filtered->slice($offset, $limit);

        $lines = [];
        $lines[] = "Account: {$account->code} — {$account->name} ({$account->type->label()})";
        $lines[] = "Balance as of {$period['end']->toDateString()}: ".$this->money($balance);
        $lines[] = "Activity window: {$period['label']} ({$period['start']->toDateString()} to {$period['end']->toDateString()})";
        $lines[] = 'Opening balance: '.$this->money($ledger['opening']);
        $lines[] = 'Closing balance: '.$this->money($ledger['closing']);
        $lines[] = '';

        $filters = array_filter([
            $contact !== null ? "contact {$contact->display_name}" : null,
            $reference !== '' ? 'reference "'.trim((string) $request->get('reference')).'"' : null,
        ]);
        $scope = $filters === [] ? '' : ' for '.implode(' and ', $filters);

        if ($all->isEmpty()) {
            $lines[] = 'No ledger activity in this period.';
        } elseif ($recent->isEmpty()) {
            $lines[] = $filtered->isEmpty()
                ? "No ledger lines in this period{$scope}."
                : "No ledger lines at offset {$offset}; there are {$filtered->count()} line(s){$scope}.";
        } else {
            $lines[] = sprintf(
                'Ledger lines%s, most recent first (%d–%d of %d):',
                $scope,
                $offset + 1,
                $offset + $recent->count(),
                $filtered->count(),
            );

            foreach ($recent as $line) {
                // A payroll line's memo names the employee: "Payroll" instead,
                // unless this connection may open pay runs (PayrollDetail).
                $text = in_array($line['entry_no'], $payrollEntries, true) ? PayrollDetail::label() : $line['memo'];
                $memo = $text !== null && $text !== '' ? " — {$text}" : '';
                $debit = $this->money($line['debit']);
                $credit = $this->money($line['credit']);
                $running = $this->money($line['running']);
                $lines[] = "- {$line['date']} {$line['entry_no']}: debit {$debit}, credit {$credit}, running {$running}{$memo}";
            }

            $remaining = $filtered->count() - ($offset + $recent->count());
            if ($remaining > 0) {
                $next = $offset + $recent->count();
                $lines[] = "{$remaining} more line(s) not shown — pass offset={$next} (and a larger limit, up to 500) to see them.";
            }
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * Any contact (customer, vendor, employee, other name) by API id, exact
     * name, or a fragment matching exactly one; a message otherwise.
     */
    private function findContact(string $needle): Contact|string
    {
        if (ctype_digit($needle) && ($byId = Contact::query()->find((int) $needle)) instanceof Contact) {
            return $byId;
        }

        $exact = Contact::query()->whereRaw('LOWER(display_name) = ?', [mb_strtolower($needle)])->get();
        if ($exact->count() === 1) {
            return $exact->first();
        }

        $matches = $this->whereContains(Contact::query(), 'display_name', $needle)->orderBy('display_name')->limit(11)->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            return "No contact matched \"{$needle}\".";
        }

        $list = $matches->take(10)->map(fn (Contact $c): string => "- {$c->display_name} (API id {$c->id})")->implode("\n");

        return "Several contacts match \"{$needle}\". Pass one of these API ids as \"contact\":\n{$list}";
    }

    /**
     * The numbers of the listed entries that are payroll entries, or voids of
     * one (PayrollDetail::sourceTypeOf()).
     *
     * @param  list<string>  $entryNumbers
     * @return list<string>
     */
    private function payrollEntryNumbers(array $entryNumbers): array
    {
        if ($entryNumbers === []) {
            return [];
        }

        return JournalEntry::query()
            ->whereIn('entry_no', array_unique($entryNumbers))
            ->get(['id', 'entry_no', 'source_type', 'reverses_entry_id'])
            ->filter(fn (JournalEntry $entry): bool => PayrollDetail::isPayroll(PayrollDetail::sourceTypeOf($entry)))
            ->map(fn (JournalEntry $entry): string => (string) $entry->entry_no)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
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
            'contact' => $schema->string()
                ->description('Optional: only lines tagged with this contact — a name, a fragment matching one contact, or its API id.'),
            'reference' => $schema->string()
                ->description('Optional: only lines whose entry number equals this, or whose memo contains it — e.g. a document number like "INV 1042".'),
            'limit' => $schema->integer()
                ->description('How many ledger lines to list (default 10, max 500).'),
            'offset' => $schema->integer()
                ->description('How many of the most recent matching lines to skip, for paging (default 0).'),
        ];
    }
}
