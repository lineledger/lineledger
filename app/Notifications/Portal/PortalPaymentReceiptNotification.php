<?php

namespace App\Notifications\Portal;

use App\Models\Company;
use App\Models\CustomerReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalPaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CustomerReceipt $receipt,
        public Company $company,
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
        $amount = number_format($this->receipt->amount_cents / 100, 2).' '.$this->company->currency_code;

        return (new MailMessage)
            ->subject(__('Payment received — :company', ['company' => $name]))
            ->greeting(__('Thank you for your payment'))
            ->line(__('We have received your payment of :amount.', ['amount' => $amount]))
            ->line(__('Receipt number: :no', ['no' => $this->receipt->receipt_no]))
            ->action(__('View your account'), route('portal.dashboard', ['company' => $this->company->slug]));
    }
}
