<?php

namespace App\Providers;

use App\Services\Insights\AI\ClaudeInsightNarrator;
use App\Services\Insights\Contracts\InsightNarrator;
use App\Services\Insights\DailyInsightGenerator;
use App\Services\Insights\InsightDetectorRegistry;
use App\Services\Insights\InsightSelector;
use App\Services\Insights\TemplateInsightNarrator;
use Illuminate\Support\ServiceProvider;

class InsightServiceProvider extends ServiceProvider
{
    /**
     * Bind the daily-insight narration layer. AI narration is doubly opt-in:
     * Claude is bound only when the operator switched it on AND a key is
     * resolvable; otherwise the deterministic template narrator is bound so
     * insights work offline and unkeyed. The per-company opt-in is checked
     * separately, at generation time (see DailyInsightGenerator).
     */
    public function register(): void
    {
        $this->app->bind(InsightNarrator::class, function ($app) {
            $config = (array) $app['config']->get('insights.ai', []);
            $key = $app['config']->get('services.anthropic.key');

            $usable = ($config['enabled'] ?? false)
                && is_string($key) && $key !== '';

            if (! $usable) {
                return new TemplateInsightNarrator;
            }

            return new ClaudeInsightNarrator(
                apiKey: (string) $key,
                baseUrl: rtrim((string) $app['config']->get('services.anthropic.base_url', 'https://api.anthropic.com'), '/'),
                model: (string) ($config['model'] ?? 'claude-sonnet-4-6'),
                timeout: (int) ($config['timeout'] ?? 20),
                fallback: new TemplateInsightNarrator,
            );
        });

        $this->app->bind(DailyInsightGenerator::class, fn ($app) => new DailyInsightGenerator(
            narrator: $app->make(InsightNarrator::class),
            template: new TemplateInsightNarrator,
            selector: new InsightSelector,
            detectors: array_map(fn (string $class) => $app->make($class), InsightDetectorRegistry::detectors()),
        ));
    }
}
