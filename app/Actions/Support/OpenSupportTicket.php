<?php

namespace App\Actions\Support;

use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\Support\SupportTicketActivityNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Opens a new support ticket for a user and notifies every Site Admin.
 *
 * These are platform-level records and never touch the general ledger.
 */
final class OpenSupportTicket
{
    public function handle(User $user, string $subject, SupportTicketType $type, string $body, ?Company $company = null): SupportTicket
    {
        $message = DB::transaction(function () use ($user, $subject, $type, $body, $company) {
            $ticket = SupportTicket::create([
                'user_id' => $user->getKey(),
                'company_id' => $company?->getKey(),
                'subject' => $subject,
                'type' => $type,
                'status' => SupportTicketStatus::Open,
                'last_activity_at' => now(),
            ]);

            return $ticket->messages()->create([
                'user_id' => $user->getKey(),
                'from_admin' => false,
                'body' => $body,
            ]);
        });

        $this->notifySiteAdmins($message, isNewTicket: true);

        return $message->ticket;
    }

    private function notifySiteAdmins(SupportTicketMessage $message, bool $isNewTicket): void
    {
        $admins = User::query()->where('site_admin', true)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new SupportTicketActivityNotification($message, $isNewTicket));
        }
    }
}
