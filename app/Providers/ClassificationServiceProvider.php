<?php

namespace App\Providers;

use App\Services\Classification\AI\ClaudeTransactionClassifier;
use App\Services\Classification\AI\NullTransactionClassifier;
use App\Services\Classification\Contracts\TransactionClassifier;
use Illuminate\Support\ServiceProvider;

class ClassificationServiceProvider extends ServiceProvider
{
    /**
     * Bind the AI fallback for transaction categorization. It deliberately reuses
     * the document-inbox AI gate (config('inbox.ai') + a resolvable Anthropic key)
     * so the operator manages one AI opt-in surface; otherwise the Null
     * implementation is bound and categorization stays history-only and offline.
     *
     * The per-company opt-in (companies.settings → inbox.ocr_enabled) is checked
     * separately at call time, since the binding cannot see the tenant.
     */
    public function register(): void
    {
        $this->app->bind(TransactionClassifier::class, function ($app) {
            $config = (array) $app['config']->get('inbox.ai', []);
            $key = $app['config']->get('services.anthropic.key');

            $usable = ($config['enabled'] ?? false)
                && ($config['driver'] ?? 'http') !== 'null'
                && is_string($key) && $key !== '';

            if (! $usable) {
                return new NullTransactionClassifier;
            }

            return new ClaudeTransactionClassifier(
                apiKey: (string) $key,
                baseUrl: rtrim((string) $app['config']->get('services.anthropic.base_url', 'https://api.anthropic.com'), '/'),
                model: (string) ($config['model'] ?? 'claude-sonnet-4-6'),
                timeout: (int) ($config['timeout'] ?? 60),
            );
        });
    }
}
