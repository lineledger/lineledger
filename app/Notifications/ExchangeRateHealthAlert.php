<?php

namespace App\Notifications;

use App\Console\Commands\CheckExchangeRateHealth;
use App\Services\Currency\ExchangeRateHealthReport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the ops address when {@see CheckExchangeRateHealth}
 * finds stale provider rates.
 *
 * Deliberately NOT queued (unlike most app notifications): this is an
 * infrastructure alarm, and the queue worker may be part of what's broken. The
 * scheduled command sends it inline so the alert doesn't depend on the very
 * background processing it might be warning about.
 */
class ExchangeRateHealthAlert extends Notification
{
    public function __construct(public ExchangeRateHealthReport $report) {}

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
            ->subject('LineLedger: exchange rates are stale')
            ->greeting('Exchange rate fetch may be failing')
            ->line($this->report->reason);

        if ($this->report->newestFetchedAt !== null) {
            $mail->line(sprintf(
                'Most recent provider rate was fetched %s UTC (about %s hours ago).',
                $this->report->newestFetchedAt->utc()->toDayDateTimeString(),
                $this->report->ageHours !== null ? rtrim(rtrim(number_format($this->report->ageHours, 1), '0'), '.') : '?',
            ));
        }

        foreach ($this->report->stalePairs as $pair) {
            $mail->line(sprintf('• %s → %s: %s', $pair['base'], $pair['quote'], $pair['reason']));
        }

        return $mail
            ->line('Check that the `rates:fetch` scheduled job is running and that the Frankfurter provider is reachable.')
            ->action('View FX health endpoint', url('/health/fx'));
    }
}
