<?php

namespace App\Notifications\Companies;

use App\Actions\Portal\FlagBrokenStripeConnection;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts a company owner that its Stripe Connect link stopped working, so card
 * payments in the customer portal are paused until they reconnect. Sent once,
 * when the broken connection is first detected (see {@see FlagBrokenStripeConnection}).
 */
class StripeConnectionBrokenNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Company $company) {}

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
            ->subject(__('Action needed: reconnect Stripe to keep accepting card payments'))
            ->greeting(__('Card payments are paused'))
            ->line(__(':company can no longer accept card payments in the customer portal — its Stripe connection stopped working. This usually means the account was disconnected or its access to LineLedger was revoked in Stripe.', [
                'company' => $this->company->name,
            ]))
            ->line(__('Reconnect your Stripe account to start accepting payments again. Existing receipts and payouts are unaffected.'))
            ->action(__('Reconnect Stripe'), route('companies.edit', ['company' => $this->company->slug]));
    }
}
