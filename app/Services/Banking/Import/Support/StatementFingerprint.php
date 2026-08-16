<?php

namespace App\Services\Banking\Import\Support;

use App\Services\Classification\Support\DescriptionNormalizer;

/**
 * A stable hash of a statement line, used to detect the same transaction across
 * re-uploads (overlapping statement periods) so nothing is imported or cleared
 * twice. When the format carries a unique id (OFX FITID) it dominates the hash.
 */
final class StatementFingerprint
{
    public static function for(int $accountId, string $isoDate, int $amountCents, string $description, ?string $externalId): string
    {
        if ($externalId !== null && $externalId !== '') {
            return sha1($accountId.'|fitid|'.$externalId);
        }

        $normalized = DescriptionNormalizer::normalize($description);

        return sha1(implode('|', [$accountId, $isoDate, $amountCents, $normalized]));
    }
}
