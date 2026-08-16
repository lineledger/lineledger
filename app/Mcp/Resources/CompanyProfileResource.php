<?php

namespace App\Mcp\Resources;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Mcp\CompanyProfilePresenter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class CompanyProfileResource extends Resource
{
    use AnswersBusinessQuestions;

    protected string $uri = 'lineledger://company/profile';

    protected string $mimeType = 'text/plain';

    protected string $title = 'Company profile';

    protected string $description = 'The company\'s profile: organization/legal type, jurisdiction, home currency, fiscal-year start and the current fiscal year\'s start and end dates (use these to frame reporting periods), tax/registration numbers, the CRA filing profile (which returns apply), and which feature modules are enabled. Read-only.';

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
}
