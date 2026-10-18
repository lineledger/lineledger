<?php

namespace App\Notifications\Portal;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalLoginLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $url,
        public Company $company,
        public int $ttlMinutes,
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
        $name = $this->company->brand_name ?: $this->company->name;

        return (new MailMessage)
            ->subject(__('Sign in to :company', ['company' => $name]))
            ->greeting(__('Sign in to your account'))
            ->line(__('Click the button below to securely view and pay your invoices from :company.', ['company' => $name]))
            ->action(__('View my invoices'), $this->url)
            ->line(__('This link expires in :minutes minutes and can only be used once.', ['minutes' => $this->ttlMinutes]))
            ->line(__('If you did not request this, you can safely ignore this email.'));
    }
}
