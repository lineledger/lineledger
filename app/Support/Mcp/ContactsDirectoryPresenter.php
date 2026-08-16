<?php

namespace App\Support\Mcp;

use App\Mcp\Resources\ContactsResource;
use App\Mcp\Tools\ContactsDirectoryTool;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

/**
 * Renders a company's customer/vendor directory as plain text for the MCP
 * server. Shared by {@see ContactsResource} and its companion
 * {@see ContactsDirectoryTool}. Each line carries the display name, roles,
 * email, active flag, and the numeric API id (see {@see ApiIdNote}).
 */
class ContactsDirectoryPresenter
{
    public function render(Company $company): string
    {
        $contacts = Contact::query()
            ->where(fn (Builder $query) => $query->where('is_customer', true)->orWhere('is_vendor', true))
            ->orderBy('display_name')
            ->get();

        if ($contacts->isEmpty()) {
            return "{$company->name} has no customers or vendors yet.";
        }

        $lines = [
            "Customers & vendors for {$company->name} ({$contacts->count()}):",
            ApiIdNote::forWritable('contact_id'),
            '',
        ];

        foreach ($contacts as $contact) {
            $roles = $this->roleLabel($contact);
            $email = filled($contact->email) ? " <{$contact->email}>" : '';
            $inactive = $contact->is_active ? '' : ' (inactive)';

            $lines[] = "• {$contact->display_name}".($roles !== '' ? " — {$roles}" : '').$email.$inactive." (API id {$contact->id})";
        }

        $lines[] = '';
        $lines[] = 'Use the find-contact tool for a single contact\'s AR/AP balance and recent activity.';

        return implode("\n", $lines);
    }

    private function roleLabel(Contact $contact): string
    {
        $roles = [];
        if ($contact->is_customer) {
            $roles[] = 'Customer';
        }
        if ($contact->is_vendor) {
            $roles[] = 'Vendor';
        }

        return implode(' & ', $roles);
    }
}
