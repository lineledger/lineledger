<?php

namespace App\Mcp\Resources;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Mcp\TaxCodesPresenter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class TaxCodesResource extends Resource
{
    use AnswersBusinessQuestions;

    protected string $uri = 'lineledger://tax/codes';

    protected string $mimeType = 'text/plain';

    protected string $title = 'Tax codes & agencies';

    protected string $description = 'The configured sales-tax agencies and tax codes (GST/HST/PST etc.): each code\'s rate, what it applies to (sales/purchases), whether it is recoverable, its agency and registration number, and the numeric API id (the tax_code_id the REST API and the propose-* write tools expect — not the tax code itself). Use this to explain how sales-tax figures are derived. Read-only.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('tax:read')) {
            return $denied;
        }

        if ($denied = $this->requireAnySection(Section::Lists, Section::Reports)) {
            return $denied;
        }

        return Response::text(app(TaxCodesPresenter::class)->render($this->company()));
    }
}
