<?php

namespace App\Enums;

enum CompanyBackupStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }
}
