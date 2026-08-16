<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content-Security-Policy violation reports from browsers and logs a
 * small, sanitized subset to the `csp` log channel. Unauthenticated by design
 * (browsers send reports with no session), so it is hardened against being a
 * log-flood or log-injection vector:
 *
 *   - oversized bodies are dropped (a report is small; anything large is abuse),
 *   - reports are sampled per config('security.csp.report_sample'),
 *   - only an allowlist of fields is logged, each truncated,
 *   - it always answers 204 and never echoes input, so it is not a probe oracle.
 *
 * Reports are logged, NOT written to the immutable security_logs table: that
 * table is tenant audit evidence and must never take unauthenticated internet
 * input.
 */
class CspReportController extends Controller
{
    /** Bodies larger than this are dropped unread — a real report is a few hundred bytes. */
    private const MAX_BYTES = 16384;

    /** Each logged field is truncated to this many characters (log-injection defense). */
    private const MAX_FIELD = 512;

    public function __invoke(Request $request): Response
    {
        // 204 for everything: never give a caller feedback to probe against.
        $noContent = response()->noContent();

        if (! config('security.csp.reporting', true)) {
            return $noContent;
        }

        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return $noContent;
        }

        $sample = (float) config('security.csp.report_sample', 1.0);
        if ($sample < 1.0 && lcg_value() > $sample) {
            return $noContent;
        }

        // Decode the raw body ourselves (returns mixed, and null on malformed)
        // rather than $request->json(), which is typed as a keyed array and both
        // mistypes an array-of-reports body and can throw on bad JSON.
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded) || $decoded === []) {
            return $noContent;
        }

        // Reporting API sends an array of reports; the legacy report-uri form
        // sends a single {"csp-report": {...}} object. Normalize to a list.
        $reports = array_is_list($decoded) ? $decoded : [$decoded];

        foreach ($reports as $report) {
            if (! is_array($report)) {
                continue;
            }

            // Legacy shape nests under "csp-report"; Reporting API nests under "body".
            $body = $report['csp-report'] ?? $report['body'] ?? $report;

            if (! is_array($body)) {
                continue;
            }

            Log::channel('csp')->info('csp-violation', $this->fields($body));
        }

        return $noContent;
    }

    /**
     * Extract only known fields, truncated. Covers both the legacy hyphenated
     * keys and the Reporting API camelCase keys.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, string>
     */
    private function fields(array $body): array
    {
        $map = [
            'document_uri' => $body['document-uri'] ?? $body['documentURL'] ?? null,
            'violated_directive' => $body['violated-directive'] ?? $body['effectiveDirective'] ?? null,
            'blocked_uri' => $body['blocked-uri'] ?? $body['blockedURL'] ?? null,
            'source_file' => $body['source-file'] ?? $body['sourceFile'] ?? null,
            'line_number' => $body['line-number'] ?? $body['lineNumber'] ?? null,
            'disposition' => $body['disposition'] ?? null,
        ];

        $fields = [];

        foreach ($map as $key => $value) {
            if ($value === null) {
                continue;
            }

            $fields[$key] = mb_substr((string) (is_scalar($value) ? $value : json_encode($value)), 0, self::MAX_FIELD);
        }

        return $fields;
    }
}
