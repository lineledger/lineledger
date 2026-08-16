<?php

namespace App\Enums;

/**
 * The shape of an uploaded bank statement file. CSV/XLSX need a column mapping
 * (their layout varies per bank); the OFX family and PDF are self-describing.
 */
enum BankStatementFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Ofx = 'ofx';
    case Qfx = 'qfx';
    case Qbo = 'qbo';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'Excel',
            self::Ofx => 'OFX',
            self::Qfx => 'QFX (Quicken)',
            self::Qbo => 'QBO (QuickBooks)',
            self::Pdf => 'PDF',
        };
    }

    /**
     * CSV and Excel are tabular with bank-specific columns, so they require a
     * column mapping before they can be parsed. Every other format is structured.
     */
    public function needsColumnMapping(): bool
    {
        return $this === self::Csv || $this === self::Xlsx;
    }

    /**
     * Whether this format carries its own per-transaction unique id (OFX FITID),
     * which gives perfect cross-import dedup.
     */
    public function hasStableTransactionIds(): bool
    {
        return $this->isOfxFamily();
    }

    public function isOfxFamily(): bool
    {
        return in_array($this, [self::Ofx, self::Qfx, self::Qbo], true);
    }

    /**
     * Best-effort detection from a file extension. Returns null for anything we
     * do not recognise so the caller can reject the upload.
     */
    public static function fromExtension(string $extension): ?self
    {
        return match (strtolower(trim($extension))) {
            'csv', 'txt' => self::Csv,
            'xlsx', 'xls' => self::Xlsx,
            'ofx' => self::Ofx,
            'qfx' => self::Qfx,
            'qbo' => self::Qbo,
            'pdf' => self::Pdf,
            default => null,
        };
    }
}
