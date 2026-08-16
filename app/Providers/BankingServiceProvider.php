<?php

namespace App\Providers;

use App\Services\Banking\Import\AI\ClaudeStatementIntelligence;
use App\Services\Banking\Import\AI\NullStatementIntelligence;
use App\Services\Banking\Import\Contracts\StatementIntelligence;
use Illuminate\Support\ServiceProvider;

class BankingServiceProvider extends ServiceProvider
{
    /**
     * Bind the statement-import AI layer. It is opt-in: Claude is used only when the
     * feature is switched on AND a key is resolvable; otherwise the deterministic
     * Null implementation is bound so the importer works offline and unkeyed.
     */
    public function register(): void
    {
        $this->app->bind(StatementIntelligence::class, function ($app) {
            $config = (array) $app['config']->get('banking.statement_import.ai', []);
            $key = $app['config']->get('services.anthropic.key');

            $usable = ($config['enabled'] ?? false)
                && ($config['driver'] ?? 'http') !== 'null'
                && is_string($key) && $key !== '';

            if (! $usable) {
                return new NullStatementIntelligence;
            }

            return new ClaudeStatementIntelligence(
                apiKey: (string) $key,
                baseUrl: rtrim((string) $app['config']->get('services.anthropic.base_url', 'https://api.anthropic.com'), '/'),
                model: (string) ($config['model'] ?? 'claude-sonnet-4-6'),
                timeout: (int) ($config['timeout'] ?? 60),
            );
        });
    }
}
