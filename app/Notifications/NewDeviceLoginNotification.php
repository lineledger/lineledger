<?php

namespace App\Notifications;

use App\Support\UserAgentSummary;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;

/**
 * "Was this you?" email sent when a user signs in from a device we have not seen
 * before. Queued — it is user-facing, not an infra alarm, so it rides the normal
 * mail queue.
 */
class NewDeviceLoginNotification extends Notification implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        public ?string $ipAddress,
        public ?string $userAgent,
        public CarbonInterface $at,
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
        return (new MailMessage)
            ->subject('New sign-in to your LineLedger account')
            ->greeting('New device sign-in')
            ->line('Your account was just signed in to from a device we have not seen before.')
            ->line('Device: '.UserAgentSummary::label($this->userAgent))
            ->line('IP address: '.($this->ipAddress ?? 'unknown'))
            ->line('Time: '.$this->at->toDayDateTimeString().' UTC')
            ->line('If this was you, no action is needed.')
            ->line('If it was not, change your password now and review your account security.')
            ->action('Review security settings', route('security.edit'));
    }
}
