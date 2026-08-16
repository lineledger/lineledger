<?php

namespace App\Services\Pdf\Slips;

use App\Services\Pdf\PdfMerger;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Draws slip values onto an official template page (FPDI-on-TCPDF, the same
 * stack as {@see PdfMerger}). One output page per call —
 * the template's first page is imported at its native size and the field map's
 * impressions are stamped with the given values (the T4 sheet carries two
 * identical employee copies). Coordinates are PDF points, top-left origin —
 * exactly what TCPDF uses when constructed with the 'pt' unit.
 */
final class SlipTemplateRenderer
{
    /**
     * @param  array{offsets: list<float>, fields: array<string, array{x: float, y: float, w: float, h: float, align: string, multiline?: bool}>}  $map
     * @param  array<string, string>  $values  field key => display text ('' / missing keys are skipped)
     */
    public function render(string $templatePath, array $map, array $values): string
    {
        $pdf = new Fpdi('P', 'pt');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);

        $pdf->setSourceFile(StreamReader::createByString((string) file_get_contents($templatePath)));
        $template = $pdf->importPage(1);
        /** @var array{width: float, height: float, orientation: string} $size */
        $size = $pdf->getTemplateSize($template);

        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($template);

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);

        foreach ($map['offsets'] as $offset) {
            foreach ($map['fields'] as $key => $field) {
                $text = trim((string) ($values[$key] ?? ''));

                if ($text === '') {
                    continue;
                }

                if ($field['multiline'] ?? false) {
                    $pdf->SetFont('helvetica', '', 8);
                    $pdf->MultiCell($field['w'], 10, $text, 0, $field['align'], false, 1, $field['x'], $field['y'] + $offset);
                    $pdf->SetFont('helvetica', '', 9);

                    continue;
                }

                $pdf->SetXY($field['x'], $field['y'] + $offset);
                // stretch mode 1: scale the text down if it would overflow the box.
                $pdf->Cell($field['w'], $field['h'], $text, 0, 0, $field['align'], false, '', 1);
            }
        }

        return $pdf->Output('slip.pdf', 'S');
    }
}
