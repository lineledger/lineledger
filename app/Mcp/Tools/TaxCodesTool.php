<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Mcp\TaxCodesPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * The tool half of the tax-codes listing; see {@see ItemsCatalogTool} for why
 * these reference listings are offered as both a tool and a resource.
 */
class TaxCodesTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Tax codes & agencies';

    protected string $description = 'The configured sales-tax agencies and tax codes (GST/HST/PST etc.) with each code\'s rate, what it applies to, whether it is recoverable, its agency, and numeric API id. Use this to look up the "API id" (the tax_code_id the REST API and the propose-* write tools expect) for a tax you know by code — the code is not the id. Read-only and never modifies data.';

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

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
