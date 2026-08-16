<?php

namespace App\Console\Commands;

use App\Notifications\SecurityAnomalyAlert;
use App\Services\Security\SecurityAnomalyScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Scans the immutable security log for anomalies over a recent window and alerts
 * ops (SOC 2 CC7.2 monitoring / CC7.3 incident response). The detectors:
 *
 *   - failed-login spike    — many LoginFailed events from a single IP
 *   - account lockout       — any LoginLockout event
 *   - mass key revocation   — a burst of ApiKeyRevoked events
 *   - privilege escalation  — a CompanyMemberRoleChanged that raised a role
 *
 * Runs hourly. On any finding it logs, emails ops (unless --no-alert), and exits
 * non-zero so the scheduler surfaces it; the accumulating run history is itself
 * the Type II evidence that "we monitor and respond to security events."
 */
class MonitorSecurityEvents extends Command
{
    protected $signature = 'security:monitor
        {--window= : Look-back window in minutes (default from config)}
        {--no-alert : Report anomalies without emailing}';

    protected $description = 'Scan the security log for anomalies and alert ops.';

    public function handle(): int
    {
        $window = (int) ($this->option('window') ?: config('services.security_alerts.window_minutes', 60));
        $since = now()->subMinutes($window);

        $anomalies = app(SecurityAnomalyScanner::class)->scan($since);

        if ($anomalies === []) {
            $this->info("No security anomalies in the last {$window} minute(s).");

            return self::SUCCESS;
        }

        foreach ($anomalies as $anomaly) {
            $this->warn('  - '.$anomaly);
        }

        Log::warning('Security anomalies detected.', [
            'window_minutes' => $window,
            'anomalies' => $anomalies,
        ]);

        if (! $this->option('no-alert')) {
            $this->sendAlert($anomalies, $window);
        }

        $this->error(count($anomalies).' security anomaly(ies) detected.');

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $anomalies
     */
    protected function sendAlert(array $anomalies, int $window): void
    {
        $email = config('services.security_alerts.alert_email');

        if (is_string($email) && $email !== '') {
            Notification::route('mail', $email)->notify(new SecurityAnomalyAlert($anomalies, $window));
            $this->line("Alert emailed to {$email}.");

            return;
        }

        $this->warn('No alert email configured (services.security_alerts.alert_email); skipping email.');
    }
}
