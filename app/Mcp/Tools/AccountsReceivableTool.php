<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Contact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AccountsReceivableTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Accounts receivable';

    protected string $description = 'Answers "who owes me money?" — lists customers with an outstanding balance, largest first, and the total owed to the company. Balances tie to the accounts-receivable control account in the general ledger. All figures are in the company\'s home currency.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('sales:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $limit = (int) $request->get('limit', 25);
        $limit = max(1, min($limit, 100));

        $customers = Contact::query()
            ->where('is_customer', true)
            ->where('ar_balance_cents', '>', 0)
            ->orderByDesc('ar_balance_cents')
            ->get();

        $total = (int) $customers->sum('ar_balance_cents');

        if ($customers->isEmpty()) {
            return Response::text("No customers currently owe {$this->company()->name} any money — accounts receivable is {$this->money(0)}.");
        }

        $shown = $customers->take($limit);

        $lines = ["Customers who owe {$this->company()->name} money (total {$this->money($total)}):", ''];

        foreach ($shown as $customer) {
            $lines[] = "• {$customer->display_name}: {$this->money((int) $customer->ar_balance_cents)}";
        }

        if ($customers->count() > $shown->count()) {
            $remaining = $customers->count() - $shown->count();
            $lines[] = '';
            $lines[] = "…and {$remaining} more customer(s) with smaller balances.";
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of customers to list, largest balance first (default 25, max 100).'),
        ];
    }
}
