<?php

namespace App\Notifications\Support;

use App\Actions\Support\OpenSupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails every Site Admin when a user opens a ticket or replies to one, so the
 * operator can meet the ~24h response target. Sent to each site admin — see
 * {@see OpenSupportTicket} / PostSupportTicketReply.
 */
class SupportTicketActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SupportTicketMessage $message,
        public bool $isNewTicket,
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
        $ticket = $this->message->ticket;

        $subject = $this->isNewTicket
            ? __('New support ticket #:id (:type)', ['id' => $ticket->id, 'type' => $ticket->type->label()])
            : __('New reply on ticket #:id — :subject', ['id' => $ticket->id, 'subject' => $ticket->subject]);

        return (new MailMessage)
            ->subject($subject)
            ->markdown('emails.support.activity', [
                'heading' => $subject,
                'submitterName' => $ticket->owner->name,
                'ticketSubject' => $ticket->subject,
                'ticketType' => $ticket->type->label(),
                'body' => $this->message->body,
                'actionUrl' => route('admin.support.show', $ticket),
            ]);
    }
}
