<?php

namespace App\Enums;

/**
 * Lifecycle of an official donation receipt. Issuance is the CRA-significant
 * event (it locks the serial number); a void retains the serial for the record.
 */
enum DonationReceiptStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Void => 'Void',
        };
    }
}
