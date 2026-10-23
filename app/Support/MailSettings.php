<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Outgoing mail that the site admin can set up from /admin/mail instead of the
 * environment, so a one-click install works before anyone edits a file.
 *
 * Settings saved there win over the MAIL_* env vars. With neither — SMTP
 * selected but no host, the state a fresh one-click install ships in — mail is
 * "not configured": messages are written to the log instead of failing the
 * request that sent them, the first account can sign in without verifying its
 * address, and site admins see a banner pointing at /admin/mail.
 *
 * {@see apply()} runs when the mail manager is first resolved (registered in
 * AppServiceProvider), so nothing touches the database until mail is sent.
 */
final class MailSettings
{
    private const KEY = 'mail';

    /** Set by {@see apply()} once it has fallen back to the log mailer. */
    private const FALLBACK_FLAG = 'mail.lineledger_log_fallback';

    /**
     * The settings saved from /admin/mail, password decrypted, or null when
     * none are saved (or they can't be read).
     *
     * @return array{host: string, port: int, username: ?string, password: ?string, from_address: string, from_name: string}|null
     */
    public static function saved(): ?array
    {
        try {
            $value = SiteSettings::get(self::KEY);
        } catch (Throwable) {
            // No database yet (a console command before migrating). Behave as
            // if nothing were saved; the environment still applies.
            return null;
        }

        if (! is_array($value) || ! is_string($value['host'] ?? null) || $value['host'] === '') {
            return null;
        }

        $password = null;

        if (is_string($value['password'] ?? null) && $value['password'] !== '') {
            try {
                $password = Crypt::decryptString($value['password']);
            } catch (DecryptException) {
                // Saved under a different APP_KEY; the admin re-enters it.
                $password = null;
            }
        }

        return [
            'host' => $value['host'],
            'port' => (int) ($value['port'] ?? 587),
            'username' => is_string($value['username'] ?? null) && $value['username'] !== '' ? $value['username'] : null,
            'password' => $password,
            'from_address' => (string) ($value['from_address'] ?? ''),
            'from_name' => (string) ($value['from_name'] ?? ''),
        ];
    }

    /**
     * Save settings from /admin/mail. A null password keeps the one already
     * saved, so the form never has to show it.
     *
     * @param  array{host: string, port: int, username: ?string, password: ?string, from_address: string, from_name: string}  $settings
     */
    public static function save(array $settings): void
    {
        $password = $settings['password'];
        $stored = $password !== null && $password !== ''
            ? Crypt::encryptString($password)
            : (SiteSettings::get(self::KEY)['password'] ?? null);

        SiteSettings::set(self::KEY, [
            'host' => $settings['host'],
            'port' => $settings['port'],
            'username' => $settings['username'],
            'password' => $stored,
            'from_address' => $settings['from_address'],
            'from_name' => $settings['from_name'],
        ]);
    }

    /** Drop the saved settings, falling back to the environment. */
    public static function forget(): void
    {
        SiteSettings::set(self::KEY, null);
    }

    /**
     * Whether mail can actually be delivered: settings were saved here, or the
     * environment names a real mailer.
     */
    public static function configured(): bool
    {
        if (self::saved() !== null) {
            return true;
        }

        return ! config(self::FALLBACK_FLAG) && ! self::smtpWithoutHost();
    }

    /**
     * Where the environment's mail goes, for the /admin/mail status line:
     * the mailer name and, for SMTP, its host.
     */
    public static function environmentSummary(): string
    {
        $mailer = (string) config('mail.default');

        if (config(self::FALLBACK_FLAG) || self::smtpWithoutHost()) {
            return 'smtp';
        }

        return $mailer === 'smtp'
            ? 'smtp://'.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port')
            : $mailer;
    }

    /**
     * Point the SMTP mailer at the saved settings; with none saved and nothing
     * to deliver through, send mail to the log rather than throwing.
     */
    public static function apply(): void
    {
        $saved = self::saved();

        if ($saved !== null) {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.url' => null,
                // Null lets Laravel pick smtps for port 465 and STARTTLS otherwise.
                'mail.mailers.smtp.scheme' => null,
                'mail.mailers.smtp.host' => $saved['host'],
                'mail.mailers.smtp.port' => $saved['port'],
                'mail.mailers.smtp.username' => $saved['username'],
                'mail.mailers.smtp.password' => $saved['password'],
                'mail.from.address' => $saved['from_address'],
                'mail.from.name' => $saved['from_name'],
                self::FALLBACK_FLAG => false,
            ]);

            return;
        }

        if (self::smtpWithoutHost()) {
            config(['mail.default' => 'log', self::FALLBACK_FLAG => true]);
        }
    }

    private static function smtpWithoutHost(): bool
    {
        return config('mail.default') === 'smtp'
            && blank(config('mail.mailers.smtp.host'))
            && blank(config('mail.mailers.smtp.url'));
    }
}
