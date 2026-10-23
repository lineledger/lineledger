<?php

namespace App\Console\Commands;

use App\Notifications\ExchangeRateHealthAlert;
use App\Services\Currency\ExchangeRateHealth;
use App\Support\AlertRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckExchangeRateHealth extends Command
{
    protected $signature = 'rates:health {--no-alert : Report status without emailing on failure}';

    protected $description = 'Verify provider exchange rates are fresh; email an alert if any pair is stale.';

    public function handle(ExchangeRateHealth $health): int
    {
        $report = $health->check();

        if ($report->healthy) {
            $this->info($report->reason);

            return self::SUCCESS;
        }

        $this->error($report->reason);

        foreach ($report->stalePairs as $pair) {
            $this->line(sprintf('  %s → %s: %s', $pair['base'], $pair['quote'], $pair['reason']));
        }

        Log::error('Exchange rate health check failed.', $report->toArray());

        if ($this->option('no-alert')) {
            return self::FAILURE;
        }

        $recipients = AlertRecipients::for('services.exchange_rates.health.alert_email');

        if ($recipients !== []) {
            Notification::route('mail', $recipients)->notify(new ExchangeRateHealthAlert($report));
            $this->line('Alert emailed to '.implode(', ', $recipients).'.');
        } else {
            $this->warn('No alert email configured (services.exchange_rates.health.alert_email) and no site admin to fall back to; skipping email.');
        }

        return self::FAILURE;
    }
}
