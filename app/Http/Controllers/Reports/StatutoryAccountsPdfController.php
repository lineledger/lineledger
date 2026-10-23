<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Reporting\UkStatutoryAccountsBuilder;
use App\Services\Reporting\UkStatutoryAccountsDocx;
use App\Services\Reporting\UkStatutoryAccountsPdf;
use App\Support\Reporting\ReportCatalog;
use App\Support\Statutory\StatutoryAccountsProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * The statutory accounts as a PDF — inline for viewing and printing, or as a
 * download (?download=1) — or as an editable Word document (?format=docx,
 * always a download).
 */
class StatutoryAccountsPdfController extends Controller
{
    public function __invoke(Request $request, Company $company, UkStatutoryAccountsBuilder $builder, UkStatutoryAccountsPdf $pdf, UkStatutoryAccountsDocx $docx): Response
    {
        // Same gating as the report hub: hidden reports cannot be printed either.
        abort_unless(array_key_exists('reports.statutory-accounts', ReportCatalog::flatten($company, $request->user())), 404);

        $profile = StatutoryAccountsProfile::forCompany($company);
        [$defaultStart, $defaultEnd] = $profile->lastCompletedYear($company->currentDateTime()->toImmutable());

        $start = $this->date($request->query('start')) ?? $defaultStart;
        $end = $this->date($request->query('end')) ?? $defaultEnd;
        abort_if($end->lt($start), 422, 'The period ends before it starts.');

        // A range spanning several years produces the accounts for the latest.
        [$start, $end] = $profile->documentPeriod($start, $end);

        $report = $builder->build($company, $start, $end);

        if ($request->query('format') === 'docx') {
            return new Response($docx->render($company, $profile, $report), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="'.$docx->filename($company, $report).'"',
            ]);
        }

        $bytes = $pdf->render($company, $profile, $report);
        $filename = $pdf->filename($company, $report);

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$filename.'"',
        ]);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
