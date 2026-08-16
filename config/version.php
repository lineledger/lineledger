<?php

$versionFile = base_path('VERSION');

return [

    /*
    |--------------------------------------------------------------------------
    | Application version
    |--------------------------------------------------------------------------
    |
    | Read from the VERSION file at the repository root so the two can never
    | drift. This value is stamped into every backup manifest and read back by
    | the restore bundle inspector, so a stale literal mislabels real archives.
    | The fallback only applies when VERSION is missing (e.g. a partial deploy).
    |
    */

    'app' => is_file($versionFile)
        ? trim((string) file_get_contents($versionFile))
        : '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Backup bundle schema version
    |--------------------------------------------------------------------------
    |
    | Bumped only when the shape of an exported bundle changes in a way the
    | restore path has to reason about. Independent of the app version.
    |
    */

    'schema' => 1,

];
