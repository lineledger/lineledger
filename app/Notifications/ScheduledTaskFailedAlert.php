<?php

namespace App\Notifications;

use App\Support\SchedulerFailureAlert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to ops when a scheduled command fails — either it exited non-zero or
 * it threw before reaching its own alerting. Wired onto every task in
 * routes/console.php via {@see SchedulerFailureAlert}.
 *
 * Deliberately NOT queued (like the other infra alarms): the queue worker may be
 * the very thing that failed, so the scheduler process sends inline.
 */
class ScheduledTaskFailedAlert extends Notification
{
    public function __construct(public string $command, public ?string $output = null) {}

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
            ->error()
            ->subject("LineLedger: scheduled task failed — {$this->command}")
            ->greeting('A scheduled task failed')
            ->line("The scheduled command `{$this->command}` did not complete successfully.");

        $output = trim((string) $this->output);

        if ($output !== '') {
            // Cap the captured output so a runaway stack trace can't bloat the mail.
            $mail->line('Output:')->line(mb_substr($output, 0, 2000));
        }

        return $mail->line('Check the scheduler and worker on the app server.');
    }
}
