<?php

namespace App\Exceptions\Posting;

use App\Contracts\ClientSafeException;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PostedDocumentDeletionException extends RuntimeException implements ClientSafeException
{
    public static function for(Model $document): self
    {
        $type = class_basename($document);

        return new self("{$type} #{$document->getKey()} is posted to the general ledger and cannot be deleted; void it instead.");
    }

    public function clientSafeMessage(): string
    {
        return 'This document is posted to the ledger and cannot be deleted; void it instead so the audit trail stays intact.';
    }
}
