<?php

namespace App\Support\Mcp;

/**
 * The one-line explanation that accompanies every MCP reference listing which
 * exposes numeric ids.
 *
 * Every one of these listings shows two numbers per row — a user-facing code (or
 * name) and the surrogate primary key — and only the latter is what `/api/v1`
 * payloads and integration configs key on. Codes get renumbered and names get
 * renamed; ids do not. Kept in one place so the listings can't drift into
 * describing the same field four different ways.
 */
final class ApiIdNote
{
    /**
     * For an id only the REST API accepts.
     *
     * @param  string  $field  The API field this id populates, e.g. `item_id`.
     */
    public static function for(string $field): string
    {
        return self::sentence($field, 'the REST API');
    }

    /**
     * For an id the propose-* MCP write tools accept as well as the REST API —
     * currently `account_id`, `contact_id` (as "customer"/"vendor"), and
     * `tax_code_id`. Items and payment methods are REST-only, so they use
     * {@see self::for()}; claiming otherwise would send an agent down a path the
     * write tools do not support.
     */
    public static function forWritable(string $field): string
    {
        return self::sentence($field, 'the REST API and the propose-* write tools');
    }

    private static function sentence(string $field, string $surfaces): string
    {
        return sprintf(
            'The "API id" is the stable numeric %s to pass to %s — it is not the code '
                .'or name, which are user-facing and can change.',
            $field,
            $surfaces,
        );
    }
}
