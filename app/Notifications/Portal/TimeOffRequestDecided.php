<?php

namespace App\Notifications\Portal;

use App\Enums\TimeOffRequestStatus;
use App\Models\Company;
use App\Models\TimeOffRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells the employee their time-off request was approved, denied, or
 * cancelled. Branded as the employing company and sent from the platform's
 * no-reply mailbox — same pattern as {@see EmployeePortalLoginLinkNotification}.
 */
class TimeOffRequestDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TimeOffRequest $request,
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
        $fromAddress = 'no-reply@'.Str::after(config('mail.from.address'), '@');

        $outcome = match ($this->request->status) {
            TimeOffRequestStatus::Approved => __('was approved'),
            TimeOffRequestStatus::Denied => __('was denied'),
            TimeOffRequestStatus::Cancelled => __('was cancelled'),
            default => __('was updated'),
        };

        $note = $this->request->decision_note ?: $this->request->manager_note;

        return (new MailMessage)
            ->subject(__('Your time-off request :outcome', ['outcome' => $outcome]))
            ->from($fromAddress, $name)
            ->line(__('Your request for :hours hours of :policy (:start to :end) :outcome.', [
                'hours' => rtrim(rtrim(number_format((float) $this->request->total_hours, 2), '0'), '.'),
                'policy' => $this->request->policy->name,
                'start' => $this->request->start_date->toDateString(),
                'end' => $this->request->end_date->toDateString(),
                'outcome' => $outcome,
            ]))
            ->when($note, fn (MailMessage $m) => $m->line(__('Comment: ":note"', ['note' => $note])))
            ->action(__('View in your portal'), route('employee-portal.time-off', ['company' => $this->company->slug]));
    }
}
