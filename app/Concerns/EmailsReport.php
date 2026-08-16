<?php

namespace App\Concerns;

use App\Notifications\Reports\ReportEmailNotification;
use App\Support\Reporting\RenderableReports;
use App\Support\Reporting\ReportSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Lets a report be emailed from its control bar: the current view's settings
 * are snapshotted and a queued notification renders the PDF (and optionally
 * Excel) at delivery time, so the recipient sees exactly what was on screen.
 * The consuming report must implement reportKey() (shared with Memorizable).
 */
trait EmailsReport
{
    public string $emailRecipients = '';

    public string $emailSubject = '';

    public string $emailBody = '';

    public bool $emailAttachXlsx = false;

    public function canEmailReport(): bool
    {
        return RenderableReports::supports($this->reportKey(), 'pdf');
    }

    public function canAttachReportXlsx(): bool
    {
        return RenderableReports::supports($this->reportKey(), 'xlsx');
    }

    /** URL for the print-friendly inline PDF, or null when the report has no PDF. */
    public function printReportUrl(): ?string
    {
        if (! $this->canEmailReport()) {
            return null;
        }

        return route('reports.print', [
            'company' => $this->company->slug,
            'reportKey' => $this->reportKey(),
        ]);
    }

    public function sendReportEmail(): void
    {
        if (! $this->canEmailReport()) {
            return;
        }

        $recipients = collect(explode(',', $this->emailRecipients))
            ->map(fn (string $email) => trim($email))
            ->filter()
            ->unique()
            ->values();

        $this->resetErrorBag('emailRecipients');

        if ($recipients->isEmpty()) {
            $this->addError('emailRecipients', __('Enter at least one email address.'));

            return;
        }

        if ($recipients->count() > 10) {
            $this->addError('emailRecipients', __('A report can be emailed to at most 10 recipients.'));

            return;
        }

        $invalid = $recipients->reject(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        if ($invalid->isNotEmpty()) {
            $this->addError('emailRecipients', __('Invalid email address: :emails', ['emails' => $invalid->implode(', ')]));

            return;
        }

        $entry = RenderableReports::get($this->reportKey());
        $label = $entry['label'];

        Notification::route('mail', $recipients->all())->notify(new ReportEmailNotification(
            company: $this->company,
            reportKey: $this->reportKey(),
            reportLabel: $label,
            settings: ReportSettings::capture($this),
            subjectLine: trim($this->emailSubject) !== '' ? trim($this->emailSubject) : null,
            body: trim($this->emailBody) !== '' ? trim($this->emailBody) : null,
            attachXlsx: $this->emailAttachXlsx && $this->canAttachReportXlsx(),
            resolvePresets: false,
            replyToAddress: Auth::user()?->email,
            senderName: Auth::user()?->name,
        ));

        $this->reset('emailRecipients', 'emailSubject', 'emailBody', 'emailAttachXlsx');

        $this->dispatch('report-email-sent');
    }
}
