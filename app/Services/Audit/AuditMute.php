<?php

namespace App\Services\Audit;

/**
 * Thread-local mute used by posting services when they touch source
 * documents — prevents the model observers from emitting duplicate
 * audit rows alongside the explicit posting-event record.
 */
class AuditMute
{
    protected static int $depth = 0;

    public static function silence(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isMuted(): bool
    {
        return self::$depth > 0;
    }
}
