<?php

namespace App\Notifications\Deadlines;

use App\Models\Company;
use App\Models\DeadlineOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The daily or weekly summary of a member's deadlines in one organization:
 * what's overdue and what's coming up.
 */
class DeadlineDigestNotification extends Notification
{
    /**
     * @param  Collection<int, DeadlineOccurrence>  $occurrences
     */
    public function __construct(
        public Collection $occurrences,
        public Company $company,
        public CarbonImmutable $today,
        public int $lookaheadDays,
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
        [$overdue, $upcoming] = $this->occurrences->partition(fn (DeadlineOccurrence $o) => $o->due_date->lt($this->today));

        $mail = (new MailMessage)
            ->subject(__('Your deadlines — :company', ['company' => $this->company->name]))
            ->greeting(__('Deadlines for :company', ['company' => $this->company->name]));

        if ($overdue->isNotEmpty()) {
            $mail->line('**'.trans_choice(':count overdue|:count overdue', $overdue->count(), ['count' => $overdue->count()]).'**');

            foreach ($overdue as $occurrence) {
                $mail->line('• '.DeadlineMail::summary($occurrence).$this->assignee($occurrence, $notifiable));
            }
        }

        if ($upcoming->isNotEmpty()) {
            $mail->line('**'.trans_choice('Due in the next :count day|Due in the next :count days', $this->lookaheadDays, ['count' => $this->lookaheadDays]).'**');

            foreach ($upcoming as $occurrence) {
                $mail->line('• '.DeadlineMail::summary($occurrence).$this->assignee($occurrence, $notifiable));
            }
        }

        return $mail
            ->action(__('Open Deadlines'), DeadlineMail::link($this->company))
            ->line(__('Change how often this arrives under Email settings on the Deadlines page.'));
    }

    private function assignee(DeadlineOccurrence $occurrence, object $notifiable): string
    {
        if ($occurrence->assigned_user_id === null) {
            return ' — '.__('unassigned');
        }

        return $occurrence->assigned_user_id === ($notifiable->id ?? null) ? '' : ' — '.($occurrence->assignee->name ?? '');
    }
}
