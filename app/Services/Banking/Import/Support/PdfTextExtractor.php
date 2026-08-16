<?php

namespace App\Services\Banking\Import\Support;

use RuntimeException;
use Smalot\PdfParser\Parser as SmalotParser;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Extracts the text layer from a statement PDF. Prefers poppler's `pdftotext -layout`
 * (which preserves columns best) when it is installed, falling back to the pure-PHP
 * smalot/pdfparser so the feature works with no system dependency.
 *
 * Note on "protected" PDFs: an owner-password permission flag (the usual "can't copy"
 * lock) is advisory and ignored by these extractors, so such statements read fine. A
 * true user-password (encrypted) PDF, or a scanned image with no text layer, cannot be
 * read here — those surface a clear error pointing the user to CSV/OFX or AI.
 */
final class PdfTextExtractor
{
    public function extract(string $path): string
    {
        $configured = (string) config('banking.statement_import.pdf.extractor', 'auto');

        if (($configured === 'auto' || $configured === 'pdftotext') && $this->pdftotextAvailable()) {
            $text = $this->viaPdftotext($path);

            if (trim($text) !== '' || $configured === 'pdftotext') {
                return $text;
            }
        }

        return $this->viaSmalot($path);
    }

    public function pdftotextAvailable(): bool
    {
        return $this->pdftotextBinary() !== null;
    }

    /**
     * Resolve the pdftotext binary. PHP-FPM (Herd/Valet) often runs with a minimal
     * PATH that omits Homebrew, so we probe the usual install locations directly
     * before falling back to `command -v`.
     */
    private function pdftotextBinary(): ?string
    {
        foreach (['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/usr/bin/pdftotext'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        if (function_exists('shell_exec')) {
            try {
                $which = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
                if ($which !== '' && is_executable($which)) {
                    return $which;
                }
            } catch (Throwable) {
                // fall through
            }
        }

        return null;
    }

    private function viaPdftotext(string $path): string
    {
        $binary = $this->pdftotextBinary();

        if ($binary === null) {
            return '';
        }

        $process = new Process([$binary, '-layout', '-nopgbrk', $path, '-']);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (Throwable) {
            return '';
        }

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    private function viaSmalot(string $path): string
    {
        try {
            return (new SmalotParser)->parseFile($path)->getText();
        } catch (Throwable $e) {
            throw new RuntimeException(
                'This PDF could not be read. If it is a scanned image or password-protected, upload a CSV or OFX export instead.',
                previous: $e,
            );
        }
    }
}
