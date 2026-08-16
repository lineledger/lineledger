<?php

namespace App\Enums;

/**
 * How a document arrived in the inbox: a manual drag-drop upload, or (later) an
 * inbound email attachment forwarded to the company's ingest address.
 */
enum InboxItemSource: string
{
    case Upload = 'upload';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Upload => __('Upload'),
            self::Email => __('Email'),
        };
    }
}
