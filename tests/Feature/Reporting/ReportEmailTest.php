<?php

use App\Models\Company;
use App\Models\User;
use App\Notifications\Reports\ReportEmailNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('queues a report email carrying the current view settings', function () {
    Notification::fake();

    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('preset', 'custom')
        ->set('startDate', '2025-03-01')
        ->set('endDate', '2025-03-31')
        ->set('emailRecipients', 'a@example.com, b@example.com')
        ->set('emailSubject', 'March P&L')
        ->set('emailBody', 'See attached.')
        ->call('sendReportEmail')
        ->assertHasNoErrors()
        ->assertDispatched('report-email-sent');

    Notification::assertSentOnDemand(
        ReportEmailNotification::class,
        function (ReportEmailNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routes['mail'] === ['a@example.com', 'b@example.com']
                && $notification->reportKey === 'reports.income-statement'
                && $notification->settings['startDate'] === '2025-03-01'
                && $notification->settings['endDate'] === '2025-03-31'
                && $notification->subjectLine === 'March P&L'
                && $notification->resolvePresets === false
                && $notification->replyToAddress === $this->user->email;
        },
    );
});

it('rejects invalid and missing recipients without sending', function () {
    Notification::fake();

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company]);

    $component->set('emailRecipients', '')->call('sendReportEmail')
        ->assertHasErrors('emailRecipients');

    $component->set('emailRecipients', 'not-an-email')->call('sendReportEmail')
        ->assertHasErrors('emailRecipients');

    $tooMany = collect(range(1, 11))->map(fn ($i) => "user{$i}@example.com")->implode(', ');
    $component->set('emailRecipients', $tooMany)->call('sendReportEmail')
        ->assertHasErrors('emailRecipients');

    Notification::assertNothingSent();
});

it('renders the PDF and optional Excel attachments at delivery time', function () {
    $notification = new ReportEmailNotification(
        company: $this->company,
        reportKey: 'reports.income-statement',
        reportLabel: 'Income Statement',
        settings: ['preset' => 'custom', 'startDate' => '2025-03-01', 'endDate' => '2025-03-31'],
        attachXlsx: true,
        replyToAddress: 'sender@example.com',
        senderName: 'Sender Name',
    );

    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($mail->rawAttachments)->toHaveCount(2);
    expect($mail->rawAttachments[0]['name'])->toBe('income-statement-2025-03-01-2025-03-31.pdf');
    expect(substr($mail->rawAttachments[0]['data'], 0, 4))->toBe('%PDF');
    expect($mail->rawAttachments[1]['name'])->toEndWith('.xlsx');

    expect($mail->from[0])->toStartWith('no-reply@');
    expect($mail->replyTo[0])->toBe(['sender@example.com', 'Sender Name']);
    expect($mail->subject)->toContain('Income Statement');
});

it('defaults the subject to the report and company name', function () {
    $notification = new ReportEmailNotification(
        company: $this->company,
        reportKey: 'reports.trial-balance',
        reportLabel: 'Trial Balance',
        settings: [],
    );

    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toContain('Trial Balance')
        ->toContain($this->company->brand_name ?: $this->company->name);
    expect($mail->rawAttachments)->toHaveCount(1);
});
