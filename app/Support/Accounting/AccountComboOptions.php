<?php

namespace App\Support\Accounting;

use JsonException;

/**
 * The payload behind <x-account-combo>: a page's account list, emitted once by
 * <x-account-combo.options> and read by every row's combo (resources/js/account-combo.js).
 *
 * The forms' option methods return different shapes — Account models, or
 * `['value' => id, 'label' => …]` / `['id' => id, 'label' => …]` pairs, or an
 * `id => label` map — so this normalizes them all to `{id, code, name}`. The
 * code travels separately from the name because the combo ranks code-prefix
 * matches first when someone types a GL number.
 */
final class AccountComboOptions
{
    /**
     * @param  iterable<mixed>  $options
     * @return list<array{id: int|string, code: string, name: string}>
     */
    public static function normalize(iterable $options): array
    {
        $normalized = [];

        foreach ($options as $key => $option) {
            $row = self::row($key, $option);

            if ($row !== null) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    /**
     * The payload as JSON that is safe inside a <script> element: `<`, `>`, `&`
     * and quotes are \u-escaped, so an account named "</script>" can't break out.
     *
     * @param  iterable<mixed>  $options
     *
     * @throws JsonException
     */
    public static function toJson(iterable $options): string
    {
        return json_encode(
            self::normalize($options),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array{id: int|string, code: string, name: string}|null
     */
    private static function row(int|string $key, mixed $option): ?array
    {
        if (is_object($option)) {
            $id = $option->id ?? null;
            $code = $option->code ?? null;
            $name = $option->name ?? null;
        } elseif (is_array($option)) {
            $id = $option['id'] ?? $option['value'] ?? null;
            $code = $option['code'] ?? null;
            $name = $option['name'] ?? $option['label'] ?? null;
        } elseif (is_scalar($option)) {
            // An `id => label` map.
            $id = $key;
            $code = null;
            $name = $option;
        } else {
            return null;
        }

        if ($id === null || $id === '' || ! is_scalar($id)) {
            return null;
        }

        return [
            'id' => is_numeric($id) ? (int) $id : (string) $id,
            'code' => is_scalar($code) ? (string) $code : '',
            'name' => is_scalar($name) ? (string) $name : '',
        ];
    }
}
