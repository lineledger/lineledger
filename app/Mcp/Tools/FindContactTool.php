<?php

namespace App\Mcp\Tools;

use App\Enums\AccountSubtype;
use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Services\Reporting\ContactStatementBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class FindContactTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Find Contact';

    protected string $description = 'Look up a single customer or vendor by name and report their accounts-receivable and accounts-payable balances plus a short recent-activity statement summary. All figures are in the company\'s home currency. This tool is read-only and never modifies any data.';

    public function handle(Request $request): Response
    {
        $key = app()->bound('current_api_key') ? app('current_api_key') : null;
        if ($key instanceof CompanyApiKey && ! $key->hasAbility('sales:read') && ! $key->hasAbility('purchases:read')) {
            return Response::error('This API key is not permitted to look up contacts.');
        }

        // A contact may be a customer or a vendor, so access to either the
        // Customers or Vendors section is sufficient for an OAuth-authenticated
        // member (mirroring the web app's section gating).
        if ($denied = $this->requireAnySection(Section::Customers, Section::Vendors)) {
            return $denied;
        }

        $name = trim((string) $request->get('name'));
        if ($name === '') {
            return Response::error('Please provide a contact name to search for.');
        }

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $name).'%';

        $matches = Contact::query()
            ->where(function ($query) use ($term): void {
                $query->whereRaw('LOWER(display_name) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(company_name) LIKE LOWER(?)', [$term]);
            })
            ->orderBy('display_name')
            ->limit(25)
            ->get();

        if ($matches->isEmpty()) {
            return Response::text("No customer or vendor matched \"{$name}\".");
        }

        if ($matches->count() > 1) {
            $lines = $matches->map(function (Contact $contact): string {
                $roles = $this->roleLabel($contact);

                return "- {$contact->display_name}".($roles !== '' ? " ({$roles})" : '');
            })->implode("\n");

            return Response::text(
                "Multiple contacts match \"{$name}\". Please be more specific:\n".$lines
            );
        }

        return Response::text($this->summarize($matches->first(), $request));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The customer or vendor name to look up (matches display name or company name, case-insensitive). Required.'),
        ];
    }

    /**
     * Build the natural-language summary for a single matched contact.
     */
    protected function summarize(Contact $contact, Request $request): string
    {
        $roles = $this->roleLabel($contact);

        $out = $contact->display_name;
        if ($roles !== '') {
            $out .= " ({$roles})";
        }
        $out .= "\n";
        $out .= 'AR balance: '.$this->money((int) $contact->ar_balance_cents)."\n";
        $out .= 'AP balance: '.$this->money((int) $contact->ap_balance_cents);

        $period = $this->resolvePeriod($request);

        if ($contact->is_customer) {
            $out .= "\n".$this->statementSummary($contact, AccountSubtype::AccountsReceivable, 'Receivable', $period);
        }

        if ($contact->is_vendor) {
            $out .= "\n".$this->statementSummary($contact, AccountSubtype::AccountsPayable, 'Payable', $period);
        }

        return $out;
    }

    /**
     * Render a short recent-activity statement for one ledger.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, label: string}  $period
     */
    protected function statementSummary(Contact $contact, AccountSubtype $subtype, string $heading, array $period): string
    {
        $builder = app(ContactStatementBuilder::class);

        $data = $builder->build(
            $this->company(),
            $contact,
            $subtype,
            $period['start'],
            $period['end'],
        );

        $out = "{$heading} statement ({$period['label']})";
        $out .= "\nOpening: ".$this->money((int) ($data['opening'] ?? 0));
        $out .= ' | Closing: '.$this->money((int) ($data['closing'] ?? 0));

        $lines = array_slice($data['lines'] ?? [], -5);
        if ($lines === []) {
            $out .= "\nNo activity in this period.";

            return $out;
        }

        $out .= "\nRecent activity:";
        foreach ($lines as $line) {
            $net = (int) ($line['debit'] ?? 0) - (int) ($line['credit'] ?? 0);
            $out .= "\n- {$line['date']} {$line['type']} {$line['doc_no']}: ".$this->money($net);
        }

        return $out;
    }

    /**
     * Build a "Customer", "Vendor", or "Customer & Vendor" label.
     */
    protected function roleLabel(Contact $contact): string
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
