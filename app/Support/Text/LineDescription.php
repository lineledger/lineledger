<?php

namespace App\Support\Text;

use Illuminate\Support\HtmlString;

/**
 * Renders a line-item description (a plain user-entered string) as safe HTML for
 * documents and customer-facing pages:
 *  - newlines become line breaks, so multi-line descriptions keep their shape;
 *  - lines beginning with "-", "*", or "•" become a real bulleted list.
 *
 * The user's text is always escaped before any markup is added, so the result is
 * safe to echo with {!! !!}. Works identically in dompdf (PDF) and the web views.
 */
class LineDescription
{
    /** A line that is a bullet: a -, *, or • marker, then end-of-line or a space + content. */
    private const BULLET_PATTERN = '/^[-*•](?:\s+(.*))?$/u';

    public static function toHtml(?string $text): HtmlString
    {
        if ($text === null || trim($text) === '') {
            return new HtmlString('');
        }

        $segments = self::segment($text);

        $html = '';
        foreach ($segments as $segment) {
            $html .= $segment['type'] === 'list'
                ? '<ul style="margin:0;padding-left:1.1em;list-style:disc;">'
                    .implode('', array_map(fn (string $item): string => '<li>'.e($item).'</li>', $segment['items']))
                    .'</ul>'
                : implode('<br>', array_map(fn (string $line): string => e($line), $segment['lines']));
        }

        return new HtmlString($html);
    }

    /**
     * Group the raw lines into ordered segments: consecutive bullet lines form a
     * single list segment; everything else accumulates into text segments.
     *
     * @return list<array{type: 'list', items: list<string>}|array{type: 'text', lines: list<string>}>
     */
    private static function segment(string $text): array
    {
        $segments = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $raw) {
            $trimmed = ltrim($raw);
            $isBullet = $trimmed !== '' && preg_match(self::BULLET_PATTERN, $trimmed, $match) === 1;

            $lastIndex = count($segments) - 1;

            if ($isBullet) {
                $item = trim($match[1] ?? '');

                if ($lastIndex >= 0 && $segments[$lastIndex]['type'] === 'list') {
                    $segments[$lastIndex]['items'][] = $item;
                } else {
                    $segments[] = ['type' => 'list', 'items' => [$item]];
                }

                continue;
            }

            if ($lastIndex >= 0 && $segments[$lastIndex]['type'] === 'text') {
                $segments[$lastIndex]['lines'][] = $raw;
            } else {
                $segments[] = ['type' => 'text', 'lines' => [$raw]];
            }
        }

        return $segments;
    }
}
