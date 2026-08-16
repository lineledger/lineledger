<?php

namespace App\Actions\Sales;

use App\Actions\Portal\IssuePortalLoginToken;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceReminderLog;
use App\Models\ReminderTier;
use App\Notifications\Sales\InvoiceReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Emails one payment reminder for an invoice at a given tier and records it, so
 * the same tier never fires twice for the same invoice. Shared by the scheduled
 * dunning run and the Reminders worklist's "Send now" button, so both honour the
 * same safety checks and logging.
 *
 * The scheduled run is gated on the customer's `reminder_emails_enabled` consent.
 * "Send now" passes $bypassOptIn — a human chasing one invoice is the explicit act
 * the flag stands in for, and it leaves the customer's setting untouched.
 */
final class SendInvoiceReminder
{
    public function __construct(protected IssuePortalLoginToken $tokens) {}

    /**
     * @param  bool  $bypassOptIn  Set only on human-initiated sends.
     * @return bool Whether a reminder was sent (false when skipped — no email, the
     *              customer has not opted in, or this tier was already logged).
     */
    public function handle(Company $company, Invoice $invoice, ReminderTier $tier, bool $bypassOptIn = false): bool
    {
        $contact = $invoice->contact;
        $email = $contact?->email;

        // Order matters: the email guards short-circuit before the contact is
        // dereferenced, so a contactless invoice can never reach the opt-in check.
        if ($email === null || $email === '' || ! $invoice->reminders_enabled) {
            return false;
        }

        if (! $bypassOptIn && ! $contact->reminder_emails_enabled) {
            return false;
        }

        // The unique (invoice_id, tier_id) index is the hard idempotency guard;
        // claim the log first so a retry or a racing worker can't double-send.
        $log = InvoiceReminderLog::query()->firstOrCreate(
            ['invoice_id' => $invoice->id, 'reminder_tier_id' => $tier->id],
            ['company_id' => $company->id, 'sent_to' => $email, 'sent_at' => Carbon::now()],
        );

        if (! $log->wasRecentlyCreated) {
            return false;
        }

        $intendedPath = route('portal.invoices.show', [
            'company' => $company->slug,
            'invoice' => $invoice->id,
        ], absolute: false);

        $payUrl = $this->tokens->handle($company, $contact, $intendedPath);

        $settings = $company->invoiceSettingsOrNew();
        $cc = $settings->email_cc_self && $settings->email_from_address ? [$settings->email_from_address] : [];

        Notification::route('mail', [$email])->notify(new InvoiceReminderNotification(
            invoice: $invoice,
            company: $company,
            payUrl: $payUrl,
            subject: $tier->renderSubject($invoice),
            body: $tier->renderBody($invoice),
            replyToAddress: $settings->email_from_address,
            senderName: $settings->email_from_name,
            cc: $cc,
        ));

        return true;
    }
}
