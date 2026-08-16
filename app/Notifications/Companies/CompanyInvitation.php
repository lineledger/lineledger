<?php

namespace App\Notifications\Companies;

use App\Models\CompanyInvitation as CompanyInvitationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CompanyInvitationModel $invitation)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = $this->invitation->company;
        $inviter = $this->invitation->inviter;

        return (new MailMessage)
            ->subject(__("You've been invited to join :companyName", ['companyName' => $company->name]))
            ->line(__(':inviterName has invited you to join the :companyName company.', [
                'inviterName' => $inviter->name,
                'companyName' => $company->name,
            ]))
            ->action(__('Accept invitation'), url("/invitations/{$this->invitation->code}/accept"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'company_id' => $this->invitation->company_id,
            'company_name' => $this->invitation->company->name,
            'role' => $this->invitation->role->value,
        ];
    }
}
