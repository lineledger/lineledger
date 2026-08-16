<?php

namespace App\Actions\Sales;

use App\Actions\Portal\IssuePortalLoginToken;
use App\Models\Company;
use App\Models\Invoice;
use App\Notifications\Sales\InvoiceSharedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Emails an invoice to a customer: mints a one-click magic link that deep-links to
 * the invoice in the portal, then sends it (with the PDF) to the confirmed address.
 * The recipient address and message are passed in from the send modal — the From
 * identity comes from the company's invoice settings.
 *
 * Automated callers (recurring "post and email" schedules) are gated on the
 * customer's `invoice_emails_enabled` consent. A human clicking Send passes
 * $bypassOptIn, because that click is the explicit act the flag stands in for.
 */
final class SendInvoiceToCustomer
{
    public function __construct(protected IssuePortalLoginToken $tokens) {}

    /**
     * @param  list<string>  $to  Primary recipients — each gets the magic link.
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  bool  $bypassOptIn  Set only on human-initiated sends.
     * @return bool Whether the invoice was sent (false when the customer has not
     *              opted in to invoice emails).
     */
    public function handle(Company $company, Invoice $invoice, array $to, string $message, array $cc = [], array $bcc = [], bool $bypassOptIn = false): bool
    {
        $contact = $invoice->contact;

        // Gate before minting a portal token, so a suppressed send costs nothing.
        if (! $bypassOptIn && ! $contact?->invoice_emails_enabled) {
            return false;
        }

        $intendedPath = route('portal.invoices.show', [
            'company' => $company->slug,
            'invoice' => $invoice->id,
        ], absolute: false);

        $payUrl = $this->tokens->handle($company, $contact, $intendedPath);

        $settings = $company->invoiceSettingsOrNew();

        Notification::route('mail', $to)->notify(new InvoiceSharedNotification(
            invoice: $invoice,
            company: $company,
            payUrl: $payUrl,
            message: $message,
            replyToAddress: $settings->email_from_address,
            senderName: $settings->email_from_name,
            cc: $cc,
            bcc: $bcc,
        ));

        return true;
    }
}
