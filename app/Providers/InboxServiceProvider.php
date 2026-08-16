<?php

namespace App\Providers;

use App\Services\Inbox\OCR\ClaudeReceiptIntelligence;
use App\Services\Inbox\OCR\Contracts\ReceiptIntelligence;
use App\Services\Inbox\OCR\NullReceiptIntelligence;
use Illuminate\Support\ServiceProvider;

class InboxServiceProvider extends ServiceProvider
{
    /**
     * Bind the document-inbox OCR layer. It is opt-in: Claude is used only when
     * the operator switches OCR on AND a key is resolvable; otherwise the Null
     * implementation is bound so the inbox works offline and unkeyed (every
     * document falls through to manual review).
     *
     * The per-company opt-in (companies.settings → inbox.ocr_enabled) is checked
     * separately, at call time, since the binding cannot see the tenant.
     */
    public function register(): void
    {
        $this->app->bind(ReceiptIntelligence::class, function ($app) {
            $config = (array) $app['config']->get('inbox.ai', []);
            $key = $app['config']->get('services.anthropic.key');

            $usable = ($config['enabled'] ?? false)
                && ($config['driver'] ?? 'http') !== 'null'
                && is_string($key) && $key !== '';

            if (! $usable) {
                return new NullReceiptIntelligence;
            }

            return new ClaudeReceiptIntelligence(
                apiKey: (string) $key,
                baseUrl: rtrim((string) $app['config']->get('services.anthropic.base_url', 'https://api.anthropic.com'), '/'),
                model: (string) ($config['model'] ?? 'claude-sonnet-4-6'),
                timeout: (int) ($config['timeout'] ?? 60),
            );
        });
    }
}
