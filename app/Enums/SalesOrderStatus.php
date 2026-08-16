<?php

namespace App\Enums;

enum SalesOrderStatus: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Whether a sales order in this status may still be edited. Locked the moment
     * any quantity has been invoiced — editing rebuilds the lines, which would
     * orphan the invoice links that track fulfillment. Only Open orders qualify.
     */
    public function isEditable(): bool
    {
        return $this === self::Open;
    }

    /**
     * Whether more of this order can still be invoiced. Cancelled and Closed
     * orders are terminal.
     */
    public function canFulfill(): bool
    {
        return in_array($this, [self::Open, self::Partial], true);
    }

    /**
     * Flux badge color for the status pill rendered in lists and on the show page.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'sky',
            self::Partial => 'amber',
            self::Closed => 'green',
            self::Cancelled => 'zinc',
        };
    }
}
