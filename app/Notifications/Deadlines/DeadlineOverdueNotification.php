<?php

namespace App\Notifications\Deadlines;

use App\Models\Company;
use App\Models\DeadlineOccurrence;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the assignee a deadline went past its due date without being marked
 * done: the day after, then weekly a few times.
 */
class DeadlineOverdueNotification extends Notification
{
    public function __construct(
        public DeadlineOccurrence $occurrence,
        public Company $company,
        public int $daysOverdue,
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
        $mail = (new MailMessage)
            ->subject(__('Overdue: :title — :company', [
                'title' => $this->occurrence->deadline->title,
                'company' => $this->company->name,
            ]))
            ->line(trans_choice('This deadline is :count day overdue.|This deadline is :count days overdue.', $this->daysOverdue, ['count' => $this->daysOverdue]));

        return DeadlineMail::details($mail, $this->company, $this->occurrence)
            ->action(__('Open the deadline'), DeadlineMail::link($this->company, $this->occurrence))
            ->line(__('If it’s already done, mark it done so the reminders stop. If it wasn’t required this time, mark it not required.'));
    }
}
