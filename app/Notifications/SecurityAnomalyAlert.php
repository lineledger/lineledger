<?php

namespace App\Notifications;

use App\Console\Commands\MonitorSecurityEvents;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to ops when {@see MonitorSecurityEvents} finds anomalous activity in
 * the security log — failed-login spikes, account lockouts, mass API-key
 * revocation, or privilege escalation (SOC 2 CC7.2/CC7.3).
 *
 * Deliberately NOT queued (like the other infra alarms): a security incident may
 * also have disrupted the queue, so the scheduled command sends inline.
 *
 * @param  list<string>  $anomalies
 */
class SecurityAnomalyAlert extends Notification
{
    public function __construct(public array $anomalies, public int $windowMinutes) {}

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
            ->subject('LineLedger: security anomalies detected')
            ->greeting('Unusual security activity')
            ->line(sprintf(
                'The security monitor flagged %d anomaly%s in the last %d minutes.',
                count($this->anomalies),
                count($this->anomalies) === 1 ? '' : 'ies',
                $this->windowMinutes,
            ));

        foreach ($this->anomalies as $anomaly) {
            $mail->line('• '.$anomaly);
        }

        return $mail->line('Review the security log and respond if this activity is unexpected.');
    }
}
