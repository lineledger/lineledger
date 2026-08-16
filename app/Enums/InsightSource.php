<?php

namespace App\Enums;

/**
 * Who wrote the words of a stored daily insight: Claude (the per-company
 * opt-in AI narration path) or the detector's own deterministic template.
 * The figures are computed by the detectors either way — the source only
 * describes the phrasing.
 */
enum InsightSource: string
{
    case Ai = 'ai';
    case Template = 'template';
}
