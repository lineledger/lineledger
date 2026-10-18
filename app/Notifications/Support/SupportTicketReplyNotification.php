<?php

namespace App\Notifications\Support;

use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Emails the ticket owner when a Site Admin replies to their support ticket, so
 * they know a response is waiting. A platform email (from LineLedger), unlike the
 * company-branded sales notifications.
 */
class SupportTicketReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SupportTicketMessage $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->message->ticket;

        return (new MailMessage)
            ->subject(__('Re: your support ticket — :subject', ['subject' => $ticket->subject]))
            ->markdown('emails.support.reply', [
                'ticketSubject' => $ticket->subject,
                'replyBody' => $this->message->body,
                'preview' => Str::limit($this->message->body, 120),
                'actionUrl' => route('support.show', $ticket),
            ]);
    }
}
