<?php

namespace App\Notifications\Deadlines;

use App\Models\Company;
use App\Models\DeadlineOccurrence;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the assignee a deadline is coming up, on each lead day the deadline
 * asks for. Sent from the per-company notification job (already queued).
 */
class DeadlineDueSoonNotification extends Notification
{
    public function __construct(
        public DeadlineOccurrence $occurrence,
        public Company $company,
        public int $daysUntil,
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
        $when = match (true) {
            $this->daysUntil <= 0 => __('Due today'),
            $this->daysUntil === 1 => __('Due tomorrow'),
            default => trans_choice('Due in :count day|Due in :count days', $this->daysUntil, ['count' => $this->daysUntil]),
        };

        $mail = (new MailMessage)
            ->subject(__(':when: :title — :company', [
                'when' => $when,
                'title' => $this->occurrence->deadline->title,
                'company' => $this->company->name,
            ]));

        return DeadlineMail::details($mail, $this->company, $this->occurrence)
            ->action(__('Open the deadline'), DeadlineMail::link($this->company, $this->occurrence))
            ->line(__('You’re getting this because the deadline is assigned to you in :company.', ['company' => $this->company->name]));
    }
}
