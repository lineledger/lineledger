<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use App\Support\Money;
use RuntimeException;

/**
 * Filing refused: the return does not agree with the agency's payable account
 * and the difference was not accepted (or has changed since it was).
 */
class TaxReturnOutOfBalanceException extends RuntimeException implements ClientSafeException
{
    public static function from(int $differenceCents, ?int $acceptedCents): self
    {
        $difference = Money::fromCents($differenceCents)->toDecimalString();

        return new self($acceptedCents === null
            ? "This return differs from the tax payable account by {$difference}. Adjust it until the difference is zero, or accept the difference to file anyway."
            : "The difference from the tax payable account is now {$difference}, not the ".Money::fromCents($acceptedCents)->toDecimalString().' you accepted. Review the return and file again.');
    }

    public function clientSafeMessage(): string
    {
        return 'The return does not agree with the tax payable account; adjust it or accept the difference to file anyway.';
    }
}
