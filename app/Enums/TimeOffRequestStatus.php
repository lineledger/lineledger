<?php

namespace App\Enums;

/**
 * Lifecycle of a time-off request through the two-level approval:
 * Pending → ManagerApproved (the absence is accepted) → Approved (payroll
 * confirmed the pay treatment; time entries generated) | Denied | Cancelled.
 */
enum TimeOffRequestStatus: string
{
    case Pending = 'pending';
    case ManagerApproved = 'manager_approved';
    case Approved = 'approved';
    case Denied = 'denied';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::ManagerApproved => __('Manager approved'),
            self::Approved => __('Approved'),
            self::Denied => __('Denied'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /** Flux badge color. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::ManagerApproved => 'sky',
            self::Approved => 'green',
            self::Denied => 'red',
            self::Cancelled => 'zinc',
        };
    }

    /** Still in flight (counts against the projected balance). */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::ManagerApproved || $this === self::Approved;
    }

    /** @return array<string, string> value => label, for select inputs. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
