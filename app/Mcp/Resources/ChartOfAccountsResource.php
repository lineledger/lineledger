<?php

namespace App\Mcp\Resources;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Mcp\ChartOfAccountsPresenter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class ChartOfAccountsResource extends Resource
{
    use AnswersBusinessQuestions;

    protected string $uri = 'lineledger://accounts/chart';

    protected string $mimeType = 'text/plain';

    protected string $title = 'Chart of accounts';

    protected string $description = 'The full chart of accounts, grouped by type, with each account\'s code, name, subtype, GIFI code and label, active flag, numeric API id (the account_id the REST API and the propose-* write tools expect — not the account code), and current (QuickBooks-style) balance in the home currency. Read-only.';

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
}
