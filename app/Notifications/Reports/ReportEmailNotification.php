<?php

namespace App\Notifications\Reports;

use App\Models\Company;
use App\Services\Reporting\Render\ReportRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Emails a report with its PDF (and optionally Excel) attached. Carries only
 * the report key + settings snapshot; the artifacts are rendered at delivery
 * time through ReportRenderer so the queued payload stays small. Sent from the
 * platform's verified address under the company's name with Reply-To pointed
 * at the sender, mirroring InvoiceSharedNotification.
 *
 * One-off sends use resolvePresets=false (reproduce exactly what the user
 * saw); scheduled sends use true (a "Last Month" view re-resolves at send
 * time, QBO semantics).
 */
class ReportEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public Company $company,
        public string $reportKey,
        public string $reportLabel,
        public array $settings,
        public ?string $subjectLine = null,
        public ?string $body = null,
        public bool $attachXlsx = false,
        public bool $resolvePresets = false,
        public ?string $replyToAddress = null,
        public ?string $senderName = null,
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
        $renderer = app(ReportRenderer::class);

        // Same rationale as InvoiceSharedNotification: send from a no-reply
        // mailbox on the platform's verified domain so DKIM/SPF align, and
        // surface the company through the display name and Reply-To.
        $fromAddress = 'no-reply@'.Str::after(config('mail.from.address'), '@');

        $subject = $this->subjectLine
            ?? __(':report from :company', ['report' => $this->reportLabel, 'company' => $companyName]);

        $mail = (new MailMessage)
            ->subject($subject)
            ->from($fromAddress, $senderName);

        if ($this->replyToAddress !== null) {
            $mail->replyTo($this->replyToAddress, $senderName);
        }

        $pdf = $renderer->render($this->company, $this->reportKey, $this->settings, 'pdf', $this->resolvePresets);

        $mail
            ->markdown('emails.report-shared', [
                'companyName' => $companyName,
                'reportLabel' => $this->reportLabel,
                'introMessage' => $this->body
                    ?? __('Please find the attached :report.', ['report' => $this->reportLabel]),
            ])
            ->attachData($pdf->bytes, $pdf->filename, ['mime' => $pdf->mime]);

        if ($this->attachXlsx) {
            $xlsx = $renderer->render($this->company, $this->reportKey, $this->settings, 'xlsx', $this->resolvePresets);
            $mail->attachData($xlsx->bytes, $xlsx->filename, ['mime' => $xlsx->mime]);
        }

        return $mail;
    }
}
