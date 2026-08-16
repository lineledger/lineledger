<?php

namespace App\Notifications\Sales;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\Reporting\InvoicePdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * An automated payment reminder for an outstanding invoice, with the tier's
 * subject/body and a one-click "view & pay" magic link plus the PDF. Mirrors
 * {@see InvoiceSharedNotification}'s company-branded delivery (platform no-reply
 * From, company Reply-To) so reminders read as the company's own.
 */
class InvoiceReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $cc
     */
    public function __construct(
        public Invoice $invoice,
        public Company $company,
        public string $payUrl,
        public string $subject,
        public string $body,
        public ?string $replyToAddress = null,
        public ?string $senderName = null,
        public array $cc = [],
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
        $amount = number_format($this->invoice->balanceCents() / 100, 2).' '.$this->company->currency_code;
        $renderer = app(InvoicePdfRenderer::class);

        $fromAddress = 'no-reply@'.Str::after(config('mail.from.address'), '@');

        $mail = (new MailMessage)
            ->subject($this->subject)
            ->from($fromAddress, $senderName);

        if ($this->replyToAddress !== null) {
            $mail->replyTo($this->replyToAddress, $senderName);
        }

        if ($this->cc !== []) {
            $mail->cc($this->cc);
        }

        return $mail
            ->markdown('emails.invoice-shared', [
                'companyName' => $companyName,
                'introMessage' => $this->body,
                'detailLine' => __('Invoice :no — :amount outstanding, due :due.', [
                    'no' => $this->invoice->invoice_no,
                    'amount' => $amount,
                    'due' => $this->invoice->due_date ? CarbonImmutable::parse($this->invoice->due_date)->toDateString() : __('on receipt'),
                ]),
                'actionUrl' => $this->payUrl,
            ])
            ->attachData($renderer->raw($this->company, $this->invoice), $renderer->filename($this->invoice), [
                'mime' => 'application/pdf',
            ]);
    }
}
