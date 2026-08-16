<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Fortify\Features;
use Laravel\Passport\Passport;

abstract class TestCase extends BaseTestCase
{
    /**
     * Whether Passport's signing keys have been ensured for this test process.
     */
    private static bool $passportKeysEnsured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePassportKeys();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * The MCP server authenticates staff users through the Passport-backed `api`
     * guard, so resolving that guard builds a resource-server CryptKey from
     * storage/oauth-public.key. Those keys are gitignored and generated
     * per-environment, so a fresh checkout (CI or a new clone) has none and every
     * MCP test would throw "Invalid key supplied". Generate them once per test
     * process when absent so the suite is self-contained.
     */
    private function ensurePassportKeys(): void
    {
        if (self::$passportKeysEnsured) {
            return;
        }

        self::$passportKeysEnsured = true;

        if (! file_exists(Passport::keyPath('oauth-private.key'))) {
            Artisan::call('passport:keys', ['--no-interaction' => true]);
        }
    }
}
