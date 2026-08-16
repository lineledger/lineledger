<?php

namespace App\Enums;

/**
 * Lifecycle of a support ticket:
 * Open (needs a Site Admin reply — new, or the user replied) →
 * Answered (an admin replied, awaiting the user) →
 * Resolved (closed by an admin). A user reply always reopens to Open.
 */
enum SupportTicketStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Answered => __('Answered'),
            self::Resolved => __('Resolved'),
        };
    }

    /** Flux badge color. */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::Answered => 'sky',
            self::Resolved => 'zinc',
        };
    }

    /** Still awaiting a Site Admin reply. */
    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    /** @return array<string, string> value => label, for select inputs. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
