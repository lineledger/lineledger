<?php

namespace App\Enums;

/**
 * What a support ticket is about. Drives triage and the badge colour on the
 * Site Admin queue. "Feature Request" replaces the old external feature-request
 * link — feature ideas now come in as tickets.
 */
enum SupportTicketType: string
{
    case General = 'general';
    case Bug = 'bug';
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::General => __('General'),
            self::Bug => __('Bug or issue'),
            self::Feature => __('Feature request'),
        };
    }

    /** Flux badge color. */
    public function color(): string
    {
        return match ($this) {
            self::General => 'zinc',
            self::Bug => 'red',
            self::Feature => 'purple',
        };
    }

    /** @return array<string, string> value => label, for select inputs. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
