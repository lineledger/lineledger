<?php

namespace App\Actions\Sales;

use App\Actions\Portal\IssuePortalLoginToken;
use App\Enums\CustomerStatementType;
use App\Models\Company;
use App\Models\Contact;
use App\Notifications\Sales\CustomerStatementNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Emails a customer statement (open invoices or account activity) with the PDF
 * attached and a magic link to the live statement in the portal. The recipient
 * address and message come from the send modal; the From identity comes from
 * the company's invoice settings.
 *
 * Unlike invoice emails there is no automated caller yet, so there is no
 * opt-in gate — every send is a human clicking Send, the explicit act the
 * `invoice_emails_enabled` flag stands in for.
 */
final class SendCustomerStatement
{
    public function __construct(protected IssuePortalLoginToken $tokens) {}

    /**
     * @param  list<string>  $to  Primary recipients — each gets the magic link.
     * @param  list<string>  $cc
     */
    public function handle(Company $company, Contact $contact, CustomerStatementType $type, ?CarbonImmutable $start, CarbonImmutable $end, array $to, string $message, array $cc = []): void
    {
        $intendedPath = route('portal.statement', ['company' => $company->slug], absolute: false);

        $statementUrl = $this->tokens->handle($company, $contact, $intendedPath);

        $settings = $company->invoiceSettingsOrNew();

        Notification::route('mail', $to)->notify(new CustomerStatementNotification(
            contact: $contact,
            company: $company,
            type: $type,
            start: $start?->toDateString(),
            end: $end->toDateString(),
            statementUrl: $statementUrl,
            message: $message,
            replyToAddress: $settings->email_from_address,
            senderName: $settings->email_from_name,
            cc: $cc,
        ));
    }
}
