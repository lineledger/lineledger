<?php

namespace App\Notifications;

use App\Console\Commands\CheckLedgerIntegrity;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the ops address when {@see CheckLedgerIntegrity} finds a company
 * whose books do not reconcile — a broken audit hash chain, an unbalanced
 * general ledger, or a drifted account-balance cache.
 *
 * Deliberately NOT queued (like {@see ExchangeRateHealthAlert}): this is an
 * integrity alarm, and whatever corrupted the ledger may also have broken the
 * queue. The scheduled command sends it inline so the alert never depends on
 * the background processing it might be warning about.
 *
 * @param  array<int|string, list<string>>  $issuesByCompany
 */
class LedgerIntegrityAlert extends Notification
{
    public function __construct(public array $issuesByCompany) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyCount = count($this->issuesByCompany);

        $mail = (new MailMessage)
            ->error()
            ->subject('LineLedger: ledger integrity check failed')
            ->greeting('A ledger integrity check did not reconcile')
            ->line(sprintf(
                '%d %s reported integrity issues during the nightly check.',
                $companyCount,
                $companyCount === 1 ? 'company' : 'companies',
            ));

        foreach ($this->issuesByCompany as $companyId => $issues) {
            $mail->line(sprintf('**Company %s:**', $companyId));

            foreach ($issues as $issue) {
                $mail->line('• '.$issue);
            }
        }

        return $mail
            ->line('Investigate before posting further activity. Run `php artisan integrity:check {company}` for detail and `php artisan audit:verify {company}` to inspect the hash chain.');
    }
}
