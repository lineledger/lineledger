<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Mcp\ChartOfAccountsPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ChartOfAccountsTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Chart of accounts';

    protected string $description = 'The full chart of accounts, grouped by type, with each account\'s code, name, subtype, GIFI code and label, active flag, numeric API id, and current (QuickBooks-style) balance in the home currency. Use this to look up the "API id" (the account_id the REST API and the propose-* write tools expect) for an account you know by code or name — the code is not the id. Read-only and never modifies data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('accounting:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Accounting)) {
            return $denied;
        }

        return Response::text(app(ChartOfAccountsPresenter::class)->render($this->company()));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
