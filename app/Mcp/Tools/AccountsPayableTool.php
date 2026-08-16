<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Contact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AccountsPayableTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Accounts payable';

    protected string $description = 'Answers "who do I owe?" — lists vendors the company still owes money, largest first, and the total payable. Balances tie to the accounts-payable control account in the general ledger. All figures are in the company\'s home currency.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('purchases:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $limit = (int) $request->get('limit', 25);
        $limit = max(1, min($limit, 100));

        $vendors = Contact::query()
            ->where('is_vendor', true)
            ->where('ap_balance_cents', '>', 0)
            ->orderByDesc('ap_balance_cents')
            ->get();

        $total = (int) $vendors->sum('ap_balance_cents');

        if ($vendors->isEmpty()) {
            return Response::text("{$this->company()->name} doesn't owe any vendors right now — accounts payable is {$this->money(0)}.");
        }

        $shown = $vendors->take($limit);

        $lines = ["Vendors {$this->company()->name} owes money (total {$this->money($total)}):", ''];

        foreach ($shown as $vendor) {
            $lines[] = "• {$vendor->display_name}: {$this->money((int) $vendor->ap_balance_cents)}";
        }

        if ($vendors->count() > $shown->count()) {
            $remaining = $vendors->count() - $shown->count();
            $lines[] = '';
            $lines[] = "…and {$remaining} more vendor(s) with smaller balances.";
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
                ->description('Maximum number of vendors to list, largest balance first (default 25, max 100).'),
        ];
    }
}
