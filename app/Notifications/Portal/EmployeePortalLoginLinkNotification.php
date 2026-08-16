<?php

namespace App\Notifications\Portal;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Magic-link sign-in email for the employee pay portal. Branded as the
 * employing company (header, footer, From display name) rather than the
 * platform, and sent from a no-reply mailbox on the platform's verified
 * domain so DKIM/SPF still align — same pattern as InvoiceSharedNotification.
 */
class EmployeePortalLoginLinkNotification extends Notification implements ShouldQueue
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
        $fromAddress = 'no-reply@'.Str::after(config('mail.from.address'), '@');

        return (new MailMessage)
            ->subject(__('Sign in to your :company pay portal', ['company' => $name]))
            ->from($fromAddress, $name)
            ->markdown('emails.portal-login-link', [
                'companyName' => $name,
                'actionUrl' => $this->url,
                'ttlMinutes' => $this->ttlMinutes,
            ]);
    }
}
