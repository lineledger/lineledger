<?php

namespace App\Services\Restore\Exceptions;

use RuntimeException;

/**
 * Thrown whenever a restore bundle (ZIP) is rejected during inspection —
 * unopenable archive, missing/malformed manifest, schema-version mismatch,
 * missing required files, etc.
 *
 * The upload UI catches a single exception type to surface a friendly
 * validation message back to the user.
 */
final class BundleValidationException extends RuntimeException {}
