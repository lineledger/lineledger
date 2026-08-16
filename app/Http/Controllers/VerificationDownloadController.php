<?php

namespace App\Http\Controllers;

use App\Services\Proof\ProofArtifactWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the downloadable proof bundle (source data + reports) for the public
 * verification page. The {test} segment is validated against a fixed allow-list
 * so it can never be used to read an arbitrary path.
 */
class VerificationDownloadController extends Controller
{
    public function __invoke(string $test): BinaryFileResponse
    {
        abort_unless(in_array($test, ['test-1', 'test-2', 'test-3'], true), 404);

        $path = ProofArtifactWriter::zipPath($test);
        abort_unless(is_file($path), 404);

        return response()->download($path, "lineledger-proof-{$test}.zip");
    }
}
