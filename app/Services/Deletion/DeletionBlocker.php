<?php

namespace App\Services\Deletion;

/**
 * One reason a transaction can't be deleted, with a link to the record to fix
 * first when there is one.
 */
final class DeletionBlocker
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $url = null,
    ) {}

    /**
     * @return array{message: string, url: ?string}
     */
    public function toArray(): array
    {
        return ['message' => $this->message, 'url' => $this->url];
    }
}
