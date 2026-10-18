<?php

namespace App\Notifications\Payroll;

use App\Models\Company;
use App\Models\TimeOffRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells payroll users a manager accepted an absence — step two (confirming the
 * pay treatment, which schedules the matching time entries) is now theirs.
 */
class TimeOffRequestAwaitingConfirmation extends Notification implements ShouldQueue
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
        $employee = $this->request->employee->display_name;

        return (new MailMessage)
            ->subject(__('Time off for :employee awaits payroll confirmation', ['employee' => $employee]))
            ->line(__('The absence was approved by :manager. Confirm the pay treatment to schedule it into payroll: :hours hours of :policy, :start to :end.', [
                'manager' => $this->request->managerDecidedBy->name,
                'hours' => rtrim(rtrim(number_format((float) $this->request->total_hours, 2), '0'), '.'),
                'policy' => $this->request->policy->name,
                'start' => $this->request->start_date->toDateString(),
                'end' => $this->request->end_date->toDateString(),
            ]))
            ->action(__('Confirm in payroll'), route('time-off-requests.index', ['company' => $this->company]));
    }
}
