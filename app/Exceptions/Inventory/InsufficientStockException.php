<?php

namespace App\Exceptions\Inventory;

use App\Contracts\ClientSafeException;
use App\Models\Item;
use RuntimeException;

class InsufficientStockException extends RuntimeException implements ClientSafeException
{
    public function __construct(
        public readonly Item $item,
        public readonly string $requestedQty,
        public readonly string $availableQty,
    ) {
        parent::__construct(sprintf(
            'Insufficient stock for %s (SKU %s): requested %s, on hand %s.',
            $item->name,
            $item->sku ?? '—',
            $requestedQty,
            $availableQty,
        ));
    }

    public static function for(Item $item, string $requestedQty, string $availableQty): self
    {
        return new self($item, $requestedQty, $availableQty);
    }

    public function clientSafeMessage(): string
    {
        return 'Insufficient stock to complete this transaction.';
    }
}
