<?php

use App\Listeners\Mail\PaceOutgoingMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;

beforeEach(function () {
    // On a whole second, so every send below starts inside one window.
    $this->travelTo(CarbonImmutable::parse('2026-11-23 14:00:00'));
});

function sendPacedTestMail(int $count): void
{
    foreach (range(1, $count) as $n) {
        Mail::mailer('array')->raw("Message {$n}", fn ($message) => $message->to('member@example.com')->subject("Message {$n}"));
    }
}

function pacedTestMailCount(): int
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()->count();
}

it('holds a send once its mailer has used up this second', function () {
    config(['mail.mailers.array.per_second' => 2]);
    Sleep::fake(syncWithCarbon: true);

    sendPacedTestMail(3);

    // Two went at once; the third waited, 100 ms at a time, for the next second.
    expect(pacedTestMailCount())->toBe(3)
        ->and(now()->format('H:i:s.v'))->toBe('14:00:01.000');
    Sleep::assertSleptTimes(10);
});

it('never holds a mailer with no per-second limit', function () {
    Sleep::fake();

    sendPacedTestMail(20);

    expect(pacedTestMailCount())->toBe(20);
    Sleep::assertNeverSlept();
});

it('limits Resend below its 10 requests a second', function () {
    expect(config('mail.mailers.resend.per_second'))->toBeGreaterThan(0)->toBeLessThan(10);
});

it('sends anyway once it has waited as long as it may', function () {
    config(['mail.mailers.array.per_second' => 1]);
    // The clock doesn't move, so the second never frees up.
    Sleep::fake();

    sendPacedTestMail(2);

    expect(pacedTestMailCount())->toBe(2);
    Sleep::assertSleptTimes(PaceOutgoingMail::MAX_WAIT_MS / 100);
});
