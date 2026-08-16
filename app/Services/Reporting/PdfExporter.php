<?php

namespace App\Services\Reporting;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Renders a Blade view to a PDF and returns a BinaryFileResponse.
 *
 * Livewire actions can't return a plain Response whose body is raw binary —
 * the framework tries to JSON-encode it and fails on non-UTF-8 bytes. Writing
 * the PDF to a temp file and returning response()->download() avoids that
 * because Livewire forwards BinaryFileResponse directly to the client.
 */
class PdfExporter
{
    /**
     * Render a Blade view to raw PDF bytes — for attaching to emails or storing.
     *
     * @param  array<string, mixed>  $data
     */
    public function raw(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)->output();
    }

    public function download(string $view, array $data, string $filename): BinaryFileResponse
    {
        $tmp = $this->tempFile();

        try {
            Pdf::loadView($view, $data)->save($tmp);
        } catch (\Throwable $e) {
            @unlink($tmp);

            throw $e;
        }

        return response()
            ->download($tmp, $filename, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    /**
     * Download already-rendered PDF bytes (e.g. an official-template slip from
     * FPDI rather than a Blade view) through the same Livewire-safe
     * BinaryFileResponse path as download().
     */
    public function downloadRaw(string $pdfBytes, string $filename): BinaryFileResponse
    {
        $tmp = $this->tempFile();

        if (file_put_contents($tmp, $pdfBytes) === false) {
            @unlink($tmp);

            throw new \RuntimeException('Could not write the PDF to a temporary file.');
        }

        return response()
            ->download($tmp, $filename, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    /** A guaranteed temp path (tempnam returning false fails loud, not later). */
    private function tempFile(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pdf-');

        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary file for the PDF.');
        }

        return $tmp;
    }

    /**
     * Renders a Blade view to a PDF and returns it inline so the browser opens
     * its own PDF viewer (and print dialog) rather than downloading the file.
     */
    public function inline(string $view, array $data, string $filename): Response
    {
        return new Response(Pdf::loadView($view, $data)->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
