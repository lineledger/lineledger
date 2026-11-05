<?php

namespace App\Support\Access;

/**
 * Where to send someone after they create a record or convert one: its edit
 * page when they may change it, otherwise its show page (same parameters).
 * QuickBooks lets a role create without modifying; landing such a person on
 * an edit page they can't open would answer 403 right after a successful save.
 */
final class RecordRedirect
{
    public static function route(string $editRoute): string
    {
        if (! str_ends_with($editRoute, '.edit') || CurrentViewer::reaches($editRoute)) {
            return $editRoute;
        }

        return substr($editRoute, 0, -strlen('.edit')).'.show';
    }
}
