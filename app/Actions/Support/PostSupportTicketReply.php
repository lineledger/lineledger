<?php

namespace App\Actions\Support;

use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\Support\SupportTicketActivityNotification;
use App\Notifications\Support\SupportTicketReplyNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Posts a reply to an existing ticket from either side of the conversation.
 *
 * A Site Admin reply moves the ticket to Answered and emails the owner; a user
 * reply reopens the ticket and emails every Site Admin. Like {@see OpenSupportTicket}
 * it never touches the GL.
 */
final class PostSupportTicketReply
{
    public function handle(SupportTicket $ticket, User $author, string $body, bool $fromAdmin): SupportTicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $author, $body, $fromAdmin) {
            $message = $ticket->messages()->create([
                'user_id' => $author->getKey(),
                'from_admin' => $fromAdmin,
                'body' => $body,
            ]);

            $ticket->forceFill([
                'status' => $fromAdmin ? SupportTicketStatus::Answered : SupportTicketStatus::Open,
                'last_activity_at' => now(),
            ])->save();

            return $message;
        });

        if ($fromAdmin) {
            $ticket->owner?->notify(new SupportTicketReplyNotification($message));
        } else {
            $admins = User::query()->where('site_admin', true)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new SupportTicketActivityNotification($message, isNewTicket: false));
            }
        }

        return $message;
    }
}
