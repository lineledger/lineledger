<?php

/*
|--------------------------------------------------------------------------
| CHANGELOG.md structure
|--------------------------------------------------------------------------
|
| A merge-conflict resolution once dropped a whole release section while the
| compare links at the bottom survived. Every release link must keep its
| heading, and every heading its link, newest first.
*/

beforeEach(function () {
    $text = (string) file_get_contents(base_path('CHANGELOG.md'));

    preg_match_all('/^## \[([^\]]+)\]/m', $text, $headings);
    preg_match_all('/^\[([^\]]+)\]: https?:\/\//m', $text, $links);

    $this->headings = $headings[1];
    $this->links = $links[1];
});

it('has a section for every release it links to', function () {
    expect($this->headings)->not->toBeEmpty()
        ->and(array_values(array_diff($this->links, $this->headings)))->toBe([])
        ->and(array_values(array_diff($this->headings, $this->links)))->toBe([]);
});

it('lists releases newest first, under Unreleased', function () {
    expect($this->headings[0])->toBe('Unreleased');

    $releases = array_slice($this->headings, 1);
    $sorted = $releases;
    usort($sorted, fn (string $a, string $b) => version_compare($b, $a));

    expect($releases)->toBe($sorted)
        ->and($releases)->toBe(array_values(array_unique($releases)));
});
