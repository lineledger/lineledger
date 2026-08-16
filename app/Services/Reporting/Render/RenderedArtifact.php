<?php

namespace App\Services\Reporting\Render;

/**
 * A report rendered to a file's bytes, ready to attach, zip, or stream.
 */
final readonly class RenderedArtifact
{
    public function __construct(
        public string $bytes,
        public string $filename,
        public string $mime,
    ) {}
}
