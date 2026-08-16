<?php

namespace App\Notifications\Payroll;

use App\Models\Company;
use App\Models\TimeOffRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the designated approver (or the payroll users, when none is set) that
 * an employee submitted a time-off request awaiting the first-level decision.
 */
class TimeOffRequestSubmitted extends Notification implements ShouldQueue
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
            ->subject(__(':employee requested time off', ['employee' => $employee]))
            ->line(__(':employee requested :hours hours of :policy from :start to :end.', [
                'employee' => $employee,
                'hours' => rtrim(rtrim(number_format((float) $this->request->total_hours, 2), '0'), '.'),
                'policy' => $this->request->policy->name,
                'start' => $this->request->start_date->toDateString(),
                'end' => $this->request->end_date->toDateString(),
            ]))
            ->when($this->request->employee_note, fn (MailMessage $m) => $m->line(__('Note: ":note"', ['note' => $this->request->employee_note])))
            ->action(__('Review the request'), route('time-off-requests.index', ['company' => $this->company]))
            ->line(__('Approve or deny it from the time-off requests page.'));
    }
}
