<?php

namespace App\Enums;

/**
 * Lifecycle of a bank statement upload as it moves through parse → match →
 * review → commit. The async job advances most of these; the user advances the
 * final commit. {@see NeedsMapping} pauses for the CSV/Excel column wizard.
 */
enum BankStatementImportStatus: string
{
    case Uploaded = 'uploaded';
    case Parsing = 'parsing';
    case NeedsMapping = 'needs_mapping';
    case Parsed = 'parsed';
    case Matching = 'matching';
    case Ready = 'ready';
    case Committed = 'committed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Parsing => 'Parsing…',
            self::NeedsMapping => 'Needs column mapping',
            self::Parsed => 'Parsed',
            self::Matching => 'Matching…',
            self::Ready => 'Ready to review',
            self::Committed => 'Committed',
            self::Failed => 'Failed',
        };
    }

    /**
     * The job is still working — the UI should keep polling.
     */
    public function isProcessing(): bool
    {
        return in_array($this, [self::Parsing, self::Matching], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Committed, self::Failed], true);
    }
}
