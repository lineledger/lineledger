<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Mcp\CompanyProfilePresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CompanyProfileTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Company profile';

    protected string $description = 'The company profile: organization/legal type, jurisdiction, home currency, fiscal-year start and the current fiscal year\'s start and end dates, tax/registration numbers, the CRA filing profile (which returns apply), and enabled feature modules. Use this to frame reporting periods correctly. Read-only and never modifies data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('accounting:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Settings)) {
            return $denied;
        }

        return Response::text(app(CompanyProfilePresenter::class)->render($this->company()));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
