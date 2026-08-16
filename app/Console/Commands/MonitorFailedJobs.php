<?php

namespace App\Console\Commands;

use App\Notifications\FailedJobsAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Hourly digest of newly failed queued jobs. The queue runs backups, restores,
 * recurring-document generation, and mail, so a job that lands in `failed_jobs`
 * is real work that silently didn't happen — nothing else in the app surfaces it.
 *
 * On any finding it logs, emails ops (unless --no-alert), and exits non-zero so
 * the scheduler surfaces it. Modeled on {@see MonitorSecurityEvents}: a scheduled
 * digest rather than a Queue::failing() hook, so it dedupes into one email per
 * window and sends from the scheduler process rather than the failing worker.
 */
class MonitorFailedJobs extends Command
{
    protected $signature = 'ops:monitor-failed-jobs
        {--window= : Look-back window in minutes (default from config)}
        {--no-alert : Report failures without emailing}';

    protected $description = 'Report queued jobs that failed in the recent window and alert ops.';

    public function handle(): int
    {
        $window = (int) ($this->option('window') ?: config('services.ops_alerts.failed_jobs_window_minutes', 60));
        $since = now()->subMinutes($window);

        // failed_at is a plain string/datetime column (not an Eloquent cast);
        // bind a 'Y-m-d H:i:s' string so the comparison is identical on MySQL and
        // SQLite (which compares the literal string).
        $rows = DB::table('failed_jobs')
            ->where('failed_at', '>=', $since->toDateTimeString())
            ->orderByDesc('failed_at')
            ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at']);

        if ($rows->isEmpty()) {
            $this->info("No failed jobs in the last {$window} minute(s).");

            return self::SUCCESS;
        }

        $failures = $rows->map(function (object $row): string {
            // Decode the payload in PHP — never a JSON predicate in WHERE (dual-DB).
            $payload = json_decode((string) $row->payload, true);
            $job = is_array($payload) ? ($payload['displayName'] ?? 'Unknown job') : 'Unknown job';
            $firstLine = trim(strtok((string) $row->exception, "\n") ?: 'no exception recorded');

            return sprintf('%s on queue "%s" (%s): %s', $job, $row->queue, $row->failed_at, $firstLine);
        })->all();

        foreach ($failures as $failure) {
            $this->warn('  - '.$failure);
        }

        $total = (int) DB::table('failed_jobs')->count();

        Log::error('Failed queued jobs detected.', [
            'window_minutes' => $window,
            'new_failures' => count($failures),
            'total_failed_jobs' => $total,
        ]);

        if (! $this->option('no-alert')) {
            $this->sendAlert($failures, $total);
        }

        $this->error(count($failures).' failed job(s) in the last '.$window.' minute(s).');

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $failures
     */
    protected function sendAlert(array $failures, int $total): void
    {
        $email = config('services.ops_alerts.alert_email');

        if (is_string($email) && $email !== '') {
            Notification::route('mail', $email)->notify(new FailedJobsAlert($failures, $total));
            $this->line("Alert emailed to {$email}.");

            return;
        }

        $this->warn('No alert email configured (services.ops_alerts.alert_email); skipping email.');
    }
}
