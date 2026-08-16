<?php

namespace App\Services\Pdf;

use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Concatenates PDF documents (e.g. dompdf-rendered report artifacts) into one
 * file via FPDI-on-TCPDF, preserving each source page's size and orientation.
 */
class PdfMerger
{
    /**
     * Merge the given PDF documents, in order, into a single PDF's bytes.
     */
    public function merge(string ...$pdfBytes): string
    {
        $pdf = $this->newFpdi();

        foreach ($pdfBytes as $bytes) {
            $pageCount = $pdf->setSourceFile(StreamReader::createByString($bytes));

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                /** @var array{width: float, height: float, orientation: string} $size */
                $size = $pdf->getTemplateSize($template);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }

        return $pdf->Output('merged.pdf', 'S');
    }

    public function pageCount(string $pdfBytes): int
    {
        return $this->newFpdi()->setSourceFile(StreamReader::createByString($pdfBytes));
    }

    /**
     * TCPDF prints its own header/footer rule lines by default, which would
     * draw over the imported page content — disable both.
     */
    private function newFpdi(): Fpdi
    {
        $pdf = new Fpdi;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        return $pdf;
    }
}
