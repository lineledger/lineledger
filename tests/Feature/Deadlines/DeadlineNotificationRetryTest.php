<?php

use App\Enums\DeadlineDigestFrequency;
use App\Jobs\SendDeadlineNotificationsForCompany;
use App\Models\Company;
use App\Models\Deadline;
use App\Models\DeadlineNotificationLog;
use App\Models\DeadlineNotificationSetting;
use App\Services\Deadlines\DeadlineOccurrenceSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

it('retries a send the mail provider refused, and sends only what had not gone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00', 'America/Vancouver'));

    $company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    app()->instance('current_company', $company);
    $owner = actAsCompanyOwner($company);

    // Due tomorrow and in a week: two "due soon" emails today.
    foreach (['2026-11-24', '2026-11-30'] as $due) {
        $deadline = Deadline::factory()->once($due)->create(['assigned_user_id' => $owner->id, 'track_from' => '2020-01-01']);
        app(DeadlineOccurrenceSync::class)->sync($deadline, $company->currentDateTime());
    }

    DeadlineNotificationSetting::query()->create(['user_id' => $owner->id, 'digest' => DeadlineDigestFrequency::Off]);
    app()->forgetInstance('current_company');

    // The second send is refused, the way Resend refuses a burst over its rate limit.
    $transport = new class extends AbstractTransport
    {
        public int $calls = 0;

        /** @var list<string> */
        public array $delivered = [];

        protected function doSend(SentMessage $message): void
        {
            if (++$this->calls === 2) {
                throw new TransportException('Request to Resend API failed. Reason: Too many requests.');
            }

            $this->delivered[] = (string) $message->getOriginalMessage()->getSubject();
        }

        public function __toString(): string
        {
            return 'refuses-once://';
        }
    };

    Mail::extend('refuses-once', fn () => $transport);
    config([
        'mail.mailers.refuses-once' => ['transport' => 'refuses-once'],
        'mail.default' => 'refuses-once',
        'queue.default' => 'database',
    ]);

    SendDeadlineNotificationsForCompany::dispatch($company->id);

    // --tries=1 is the worker's default; the job's own setting has to win.
    $work = fn () => Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
    $logged = fn () => DeadlineNotificationLog::query()->withoutGlobalScopes()->count();

    $work();

    // One went; the refused one released its claim and the job waits to retry.
    expect($transport->delivered)->toHaveCount(1)
        ->and($logged())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->value('attempts'))->toBe(1);

    // Not before its backoff…
    $work();
    expect($transport->calls)->toBe(2);

    // …then the retry sends the one that was refused, and not the other again.
    $this->travel(61)->seconds();
    $work();

    expect($transport->delivered)->toHaveCount(2)
        ->and(array_unique($transport->delivered))->toHaveCount(2)
        ->and($logged())->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});
