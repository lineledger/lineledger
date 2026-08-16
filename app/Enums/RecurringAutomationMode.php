<?php

namespace App\Enums;

/**
 * What a recurring schedule does with each generated occurrence:
 *  - Draft: create it unposted for a human to review and post (the default);
 *  - Post: post it to the books automatically;
 *  - PostAndEmail: post it and email it to the customer.
 *
 * Automation applies to invoice schedules only — bill schedules always draft.
 */
enum RecurringAutomationMode: string
{
    case Draft = 'draft';

    case Post = 'post';

    case PostAndEmail = 'post_and_email';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Save as a draft for review'),
            self::Post => __('Issue automatically (post to the books)'),
            self::PostAndEmail => __('Issue and email each invoice automatically'),
        };
    }

    public function postsAutomatically(): bool
    {
        return $this !== self::Draft;
    }

    public function emailsAutomatically(): bool
    {
        return $this === self::PostAndEmail;
    }
}
