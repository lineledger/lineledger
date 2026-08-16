<?php

namespace App\Notifications\Sales;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\Reporting\InvoicePdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Emails an invoice to a customer with a one-click "View & pay" magic link and
 * the invoice PDF attached. Sent from the platform's verified address (so DKIM/SPF
 * align) but shown under the company's name, with Reply-To pointed at the company
 * so customer replies reach them. The message body is editable per send. The PDF
 * is rendered at delivery time so the queued job stays small.
 */
class InvoiceSharedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    public function __construct(
        public Invoice $invoice,
        public Company $company,
        public string $payUrl,
        public string $message,
        public ?string $replyToAddress = null,
        public ?string $senderName = null,
        public array $cc = [],
        public array $bcc = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyName = $this->company->brand_name ?: $this->company->name;
        $senderName = $this->senderName ?: $companyName;
        $amount = number_format($this->invoice->total_cents / 100, 2).' '.$this->company->currency_code;
        $renderer = app(InvoicePdfRenderer::class);

        // Send from a no-reply mailbox on the platform's verified domain so the
        // envelope still aligns with DKIM/SPF — Resend verifies the whole domain,
        // not individual mailboxes. We can't send *as* the company's own address
        // (that domain isn't verified with our sender), so the company is surfaced
        // through the display name and Reply-To instead, and the From is a no-reply
        // nobody is expected to write back to.
        $fromAddress = 'no-reply@'.Str::after(config('mail.from.address'), '@');

        $mail = (new MailMessage)
            ->subject(__('Invoice :no from :company', ['no' => $this->invoice->invoice_no, 'company' => $companyName]))
            ->from($fromAddress, $senderName);

        if ($this->replyToAddress !== null) {
            $mail->replyTo($this->replyToAddress, $senderName);
        }

        if ($this->cc !== []) {
            $mail->cc($this->cc);
        }

        if ($this->bcc !== []) {
            $mail->bcc($this->bcc);
        }

        // Custom markdown view so the email reads as the company's, not the
        // platform's: no "LineLedger" header, no "Regards, LineLedger" sign-off.
        return $mail
            ->markdown('emails.invoice-shared', [
                'companyName' => $companyName,
                'introMessage' => $this->message,
                'detailLine' => __('Invoice :no — :amount, due :due.', [
                    'no' => $this->invoice->invoice_no,
                    'amount' => $amount,
                    'due' => $this->invoice->due_date?->toDateString() ?? __('on receipt'),
                ]),
                'actionUrl' => $this->payUrl,
            ])
            ->attachData($renderer->raw($this->company, $this->invoice), $renderer->filename($this->invoice), [
                'mime' => 'application/pdf',
            ]);
    }
}
