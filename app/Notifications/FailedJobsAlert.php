<?php

namespace App\Notifications;

use App\Console\Commands\MonitorFailedJobs;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to ops when {@see MonitorFailedJobs} finds jobs in the failed_jobs
 * table from the recent window.
 *
 * Deliberately NOT queued: the queue is the thing that's broken, so sending
 * through it would drop the alert. The scheduler process sends inline.
 *
 * @param  list<string>  $failures
 */
class FailedJobsAlert extends Notification
{
    public function __construct(public array $failures, public int $totalFailed) {}

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
            ->subject('LineLedger: queued jobs failed')
            ->greeting('Queued jobs failed')
            ->line(sprintf(
                '%d job%s failed recently (%d total in the failed_jobs table).',
                count($this->failures),
                count($this->failures) === 1 ? '' : 's',
                $this->totalFailed,
            ));

        foreach ($this->failures as $failure) {
            $mail->line('• '.$failure);
        }

        return $mail->line('Inspect with `php artisan queue:failed` and retry with `php artisan queue:retry`.');
    }
}
