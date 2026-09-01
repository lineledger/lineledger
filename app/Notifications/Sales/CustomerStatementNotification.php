<?php

namespace App\Notifications\Sales;

use App\Enums\CustomerStatementType;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Reporting\CustomerStatementPdfRenderer;
use App\Services\Reporting\OpenDocumentAgingBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Emails a customer statement with a magic link to the live portal statement
 * and the statement PDF attached. Sent from the platform's verified address
 * (so DKIM/SPF align) but shown under the company's name, with Reply-To
 * pointed at the company. Dates travel as Y-m-d strings so the queued payload
 * serializes trivially; the PDF is rendered at delivery time so the job stays
 * small.
 */
class CustomerStatementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $cc
     */
    public function __construct(
        public Contact $contact,
        public Company $company,
        public CustomerStatementType $type,
        public ?string $start,
        public string $end,
        public string $statementUrl,
        public string $message,
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
        $end = CarbonImmutable::parse($this->end);
        $start = $this->start !== null ? CarbonImmutable::parse($this->start) : null;

        // The GL balance as of the statement's end date — the same figure both
        // statement types print as their total.
        $balance = app(OpenDocumentAgingBuilder::class)
            ->summaryRowForContact($this->company, 'ar', $end, $this->contact)['total'];
        $amount = number_format($balance / 100, 2).' '.$this->company->currency_code;

        $renderer = app(CustomerStatementPdfRenderer::class);

        // Send from a no-reply mailbox on the platform's verified domain so the
        // envelope still aligns with DKIM/SPF; the company is surfaced through
        // the display name and Reply-To (see InvoiceSharedNotification).
        $fromAddress = 'no-reply@'.Str::after(config('mail.from.address'), '@');

        $mail = (new MailMessage)
            ->subject(__('Statement from :company', ['company' => $companyName]))
            ->from($fromAddress, $senderName);

        if ($this->replyToAddress !== null) {
            $mail->replyTo($this->replyToAddress, $senderName);
        }

        if ($this->cc !== []) {
            $mail->cc($this->cc);
        }

        return $mail
            ->markdown('emails.statement-shared', [
                'companyName' => $companyName,
                'introMessage' => $this->message,
                'detailLine' => __('Statement as of :date — balance :amount.', [
                    'date' => $end->toDateString(),
                    'amount' => $amount,
                ]),
                'actionUrl' => $this->statementUrl,
            ])
            ->attachData(
                $renderer->raw($this->company, $this->contact, $this->type, $start, $end),
                $renderer->filename($this->contact, $this->type, $end),
                ['mime' => 'application/pdf'],
            );
    }
}
