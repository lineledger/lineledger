<?php

namespace App\Listeners\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Holds each outgoing message until its mailer has a free slot in this second,
 * for a mailer whose config/mail.php entry sets `per_second`.
 *
 * Resend refuses more than 10 API requests a second per account. Deadline
 * emails go out in the first hourly run after 07:00 in each organization's own
 * timezone, so every organization in one timezone sends in the same minute,
 * and on a Monday that includes every member's weekly digest. That burst went
 * over the limit and the refused sends failed their jobs. The count is kept in
 * the cache, so every worker and web request shares one budget.
 *
 * Laravel's listener discovery registers it; adding an Event::listen() too
 * would run it twice per message.
 */
final class PaceOutgoingMail
{
    /**
     * The longest a message waits for a slot. After that it is sent anyway:
     * a cache that has stopped expiring keys must not stop every email, and a
     * refused send fails the way it did before this limit existed.
     */
    public const int MAX_WAIT_MS = 10_000;

    private const int POLL_MS = 100;

    public function handle(MessageSending $event): void
    {
        $mailer = (string) ($event->data['mailer'] ?? config('mail.default'));
        $perSecond = (int) config("mail.mailers.{$mailer}.per_second");

        if ($perSecond < 1) {
            return;
        }

        // hit() increments atomically and returns the new count, so two workers
        // can't both take the last slot. Polls that lose only add to a second
        // that is already full.
        for ($waited = 0; RateLimiter::hit("mail-pace:{$mailer}", 1) > $perSecond && $waited < self::MAX_WAIT_MS; $waited += self::POLL_MS) {
            Sleep::for(self::POLL_MS)->milliseconds();
        }
    }
}
