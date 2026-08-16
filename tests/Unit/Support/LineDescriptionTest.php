<?php

use App\Support\Text\LineDescription;

it('returns empty html for null or blank input', function () {
    expect((string) LineDescription::toHtml(null))->toBe('')
        ->and((string) LineDescription::toHtml('   '))->toBe('');
});

it('renders newlines as line breaks', function () {
    expect((string) LineDescription::toHtml("First line\nSecond line"))
        ->toBe('First line<br>Second line');
});

it('renders -, *, and • bullet lines as a list', function () {
    $html = (string) LineDescription::toHtml("- one\n* two\n• three");

    expect($html)->toBe('<ul style="margin:0;padding-left:1.1em;list-style:disc;"><li>one</li><li>two</li><li>three</li></ul>');
});

it('mixes a heading line with a following bullet list', function () {
    $html = (string) LineDescription::toHtml("Consulting included:\n- discovery\n- delivery");

    expect($html)->toBe('Consulting included:<ul style="margin:0;padding-left:1.1em;list-style:disc;"><li>discovery</li><li>delivery</li></ul>');
});

it('escapes HTML in both plain and bullet text', function () {
    $plain = (string) LineDescription::toHtml('<script>alert(1)</script>');
    $bullet = (string) LineDescription::toHtml('- <b>x</b> & y');

    expect($plain)->toBe('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($bullet)->toContain('&lt;b&gt;x&lt;/b&gt; &amp; y')
        ->and($bullet)->not->toContain('<b>x</b>');
});

it('does not treat a hyphen mid-word as a bullet', function () {
    expect((string) LineDescription::toHtml('well-known item'))->toBe('well-known item');
});
