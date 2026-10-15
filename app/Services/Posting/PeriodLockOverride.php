<?php

namespace App\Services\Posting;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;

/**
 * Scoped permission to post into a company's closed period, granted for the
 * duration of one confirmed user action (a "Warn and confirm" acknowledgement,
 * or an Owner/Admin password override in "Block" mode). While it runs,
 * {@see Company::isLockedFor()} reports that company's locked dates as open,
 * so every poster's existing lock check lets the action through.
 *
 * Only interactive forms open one, via HandlesPeriodLockOverride; the API, MCP
 * and scheduled jobs never do. Every locked date the action actually crossed is
 * collected so the caller can write it to the audit trail.
 */
class PeriodLockOverride
{
    protected static ?int $companyId = null;

    /** @var array<string, true> */
    protected static array $crossed = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return array{0: T, 1: list<string>} The callback's result and the locked dates it crossed.
     */
    public static function run(Company $company, Closure $callback): array
    {
        $previousCompany = self::$companyId;
        $previousCrossed = self::$crossed;

        self::$companyId = (int) $company->id;
        self::$crossed = [];

        try {
            $result = $callback();
            $crossed = array_keys(self::$crossed);
            sort($crossed);

            return [$result, $crossed];
        } finally {
            self::$companyId = $previousCompany;
            self::$crossed = $previousCrossed;
        }
    }

    /**
     * Whether a locked date of $company is currently overridden; records the
     * date as crossed when it is.
     */
    public static function allows(Company $company, CarbonInterface $date): bool
    {
        if (self::$companyId === null || self::$companyId !== (int) $company->id) {
            return false;
        }

        self::$crossed[CarbonImmutable::parse($date)->toDateString()] = true;

        return true;
    }

    public static function isActive(): bool
    {
        return self::$companyId !== null;
    }
}
