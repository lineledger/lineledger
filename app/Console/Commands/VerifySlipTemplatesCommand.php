<?php

namespace App\Console\Commands;

use App\Services\Pdf\PdfMerger;
use App\Services\Pdf\Slips\SlipFieldMaps;
use App\Services\Pdf\Slips\SlipTemplateRegistry;
use App\Services\Pdf\Slips\SlipTemplateRenderer;
use Illuminate\Console\Command;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Proves the official slip templates installed for a year actually work:
 * the PDF imports under FPDI (flattened, no XFA/encryption), a field map
 * exists, and a sample render lands its marker values on every impression.
 * Run after installing a new year's template — and in CI for shipped years —
 * so a bad template fails loud instead of at year-end printing time.
 */
class VerifySlipTemplatesCommand extends Command
{
    protected $signature = 'payroll:verify-slip-templates {year? : Tax year to verify (default: current year and the one before)}';

    protected $description = 'Verify the official year-end slip templates import, map, and render correctly';

    public function handle(SlipTemplateRegistry $registry, SlipTemplateRenderer $renderer, PdfMerger $merger): int
    {
        $years = $this->argument('year') !== null
            ? [(int) $this->argument('year')]
            : [(int) date('Y') - 1, (int) date('Y')];

        $failures = 0;

        foreach ($years as $year) {
            foreach ([SlipTemplateRegistry::T4, SlipTemplateRegistry::T4A, SlipTemplateRegistry::RL1] as $type) {
                $path = $registry->path($type, $year);
                $map = SlipFieldMaps::for($type, $year);

                if ($path === null && $map === null) {
                    $this->line(sprintf('%-5s %d  — not installed (facsimile fallback)', strtoupper($type), $year));

                    continue;
                }

                if ($path === null || $map === null) {
                    $this->error(sprintf('%-5s %d  ✗ %s', strtoupper($type), $year, $path === null ? 'field map exists but no template PDF' : 'template PDF exists but no field map'));
                    $failures++;

                    continue;
                }

                try {
                    $merger->pageCount((string) file_get_contents($path));

                    // Stamp a distinct marker into every field and prove each
                    // impression carries it back out.
                    $marker = '98765.43';
                    $values = array_fill_keys(array_keys($map['fields']), $marker);
                    $bytes = $renderer->render($path, $map, $values);

                    $found = substr_count((new Parser)->parseContent($bytes)->getText(), $marker);
                    $expected = count($map['fields']) * count($map['offsets']);

                    if ($found < $expected) {
                        $this->error(sprintf('%-5s %d  ✗ rendered %d/%d field impressions', strtoupper($type), $year, $found, $expected));
                        $failures++;

                        continue;
                    }

                    $this->info(sprintf('%-5s %d  ✓ %s (%d fields × %d copies)', strtoupper($type), $year, basename($path), count($map['fields']), count($map['offsets'])));
                } catch (Throwable $e) {
                    $this->error(sprintf('%-5s %d  ✗ %s', strtoupper($type), $year, $e->getMessage()));
                    $failures++;
                }
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
