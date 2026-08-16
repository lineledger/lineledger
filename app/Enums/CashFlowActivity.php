<?php

namespace App\Enums;

use App\Models\Account;
use App\Support\Reporting\CashFlowBucket;

/**
 * The three activities on the indirect Statement of Cash Flows, in presentation
 * order. Backs the per-account {@see Account::$cash_flow_activity}
 * override and is the single source of truth for activity labels, consumed by
 * {@see CashFlowBucket::labels()}.
 */
enum CashFlowActivity: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Operating Activities',
            self::Investing => 'Investing Activities',
            self::Financing => 'Financing Activities',
        };
    }
}
