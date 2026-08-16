<?php

namespace App\Enums;

enum CompanyRestoreStatus: string
{
    case Pending = 'pending';
    case Inspecting = 'inspecting';
    case Ready = 'ready';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Inspecting => 'Inspecting',
            self::Ready => 'Ready',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
