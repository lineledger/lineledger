<?php

namespace App\Enums;

/**
 * Service Canada ROE "reason for issuing" codes (Block 16).
 */
enum RoeReason: string
{
    case ShortageOfWork = 'A';
    case Quit = 'E';
    case Illness = 'D';
    case Dismissal = 'M';
    case Retirement = 'G';
    case Leave = 'N';
    case Other = 'K';

    public function label(): string
    {
        return match ($this) {
            self::ShortageOfWork => __('A — Shortage of work / layoff'),
            self::Quit => __('E — Quit'),
            self::Illness => __('D — Illness or injury'),
            self::Dismissal => __('M — Dismissal'),
            self::Retirement => __('G — Retirement'),
            self::Leave => __('N — Leave of absence'),
            self::Other => __('K — Other'),
        };
    }
}
