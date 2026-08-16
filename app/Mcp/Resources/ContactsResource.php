<?php

namespace App\Mcp\Resources;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\CompanyApiKey;
use App\Support\Mcp\ContactsDirectoryPresenter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class ContactsResource extends Resource
{
    use AnswersBusinessQuestions;

    protected string $uri = 'lineledger://contacts/directory';

    protected string $mimeType = 'text/plain';

    protected string $title = 'Contacts directory';

    protected string $description = 'A directory of customers and vendors: display name, whether each is a customer and/or vendor, email, active status, and the numeric API id (the contact_id the REST API and the propose-* write tools expect). For a single contact\'s AR/AP balance and recent activity, use the find-contact tool instead. Read-only.';

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
}
