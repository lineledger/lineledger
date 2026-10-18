<?php

namespace App\Notifications\Companies;

use App\Models\CompanyInvitation as CompanyInvitationModel;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CompanyInvitationModel $invitation)
    {
        // Sent to a bare email address. If it already belongs to a user who picked
        // an interface language, write in that; otherwise use the organization's
        // document language. Laravel renders the whole message in this locale.
        $existing = User::query()->where('email', $invitation->email)->value('locale');

        $this->locale = Locales::isSupported($existing)
            ? $existing
            : Locales::forDocument(null, $invitation->company);
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
