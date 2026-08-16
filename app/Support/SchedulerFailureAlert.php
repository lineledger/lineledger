<?php

namespace App\Support;

use App\Notifications\ScheduledTaskFailedAlert;
use Closure;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Stringable;

/**
 * Builds the `->onFailure(...)` callback attached to every scheduled task in
 * routes/console.php, so a task that exits non-zero or throws emails ops instead
 * of failing silently. A crashing command is otherwise invisible: nothing in the
 * app surfaces it, which is exactly the gap this closes.
 *
 * Type-hinting the callback parameter as Stringable makes the scheduler pass the
 * task's captured output (Laravel injects it when the closure asks for it).
 */
class SchedulerFailureAlert
{
    /**
     * @return Closure(Stringable): void
     */
    public static function for(string $command): Closure
    {
        return function (Stringable $output) use ($command): void {
            $email = config('services.ops_alerts.alert_email');

            if (! is_string($email) || $email === '') {
                return;
            }

            Notification::route('mail', $email)
                ->notify(new ScheduledTaskFailedAlert($command, (string) $output));
        };
    }
}
