<?php

namespace App\Exceptions\Security;

use App\Contracts\ClientSafeException;
use App\Enums\SensitiveFeature;
use App\Support\Security\SensitiveFeatureGate;
use RuntimeException;

/**
 * A feature the installation keeps behind two-factor authentication was used
 * without it ({@see SensitiveFeatureGate::ensure()}). The message says whose
 * account needs it and where to turn it on.
 */
class TwoFactorRequiredException extends RuntimeException implements ClientSafeException
{
    public function __construct(public readonly SensitiveFeature $feature, string $message)
    {
        parent::__construct($message);
    }

    public function clientSafeMessage(): string
    {
        return $this->getMessage();
    }
}
