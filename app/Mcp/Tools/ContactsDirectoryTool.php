<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\CompanyApiKey;
use App\Support\Mcp\ContactsDirectoryPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * The tool half of the contacts directory; see {@see ItemsCatalogTool} for why
 * these reference listings are offered as both a tool and a resource. This lists
 * every customer/vendor — {@see FindContactTool} answers about one of them.
 */
class ContactsDirectoryTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Contacts directory';

    protected string $description = 'Every customer and vendor with their display name, roles, email, active status, and numeric API id. Use this to look up the "API id" (the contact_id the REST API and the propose-* write tools expect) for a contact you know by name, or to list who exists; for one contact\'s AR/AP balance and recent activity use the find-contact tool. Read-only and never modifies data.';

    public function handle(Request $request): Response
    {
        // Mirror FindContactTool's gating: a contact may be a customer or a vendor,
        // so either ability/section is sufficient.
        $key = app()->bound('current_api_key') ? app('current_api_key') : null;
        if ($key instanceof CompanyApiKey && ! $key->hasAbility('sales:read') && ! $key->hasAbility('purchases:read')) {
            return Response::error('This API key is not permitted to read contacts.');
        }

        if ($denied = $this->requireAnySection(Section::Customers, Section::Vendors)) {
            return $denied;
        }

        return Response::text(app(ContactsDirectoryPresenter::class)->render($this->company()));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
