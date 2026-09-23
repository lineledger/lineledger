<?php

use App\Support\Translation\KeyExtractor;

it('extracts single- and double-quoted __() keys', function () {
    $source = <<<'PHP'
        __('Save')
        __("Cancel")
        __('  spaced  ')
    PHP;

    expect(KeyExtractor::keysIn($source))->toBe(['Save', 'Cancel', '  spaced  ']);
});

it('unescapes apostrophes in single-quoted __() keys', function () {
    expect(KeyExtractor::keysIn("<?php __('can\\'t');"))->toBe(["can't"]);
});

it('extracts Livewire Title attribute strings as referenced keys', function () {
    $source = <<<'PHP'
        new #[Layout('layouts.portal')] #[Title('Your invoices')] class extends Component {}
        new #[Layout('layouts.onboarding'), Title('Review our terms')] class extends Component {}
        new #[Title("Cash flow")] class extends Component {}
    PHP;

    expect(KeyExtractor::titleKeysIn($source))->toBe([
        'Your invoices',
        'Review our terms',
        'Cash flow',
    ]);
});

it('does not treat effectiveTitle as a Title attribute', function () {
    expect(KeyExtractor::titleKeysIn('public function effectiveTitle(string $default): string'))->toBe([]);
});

it('extracts trans_choice keys', function () {
    expect(KeyExtractor::keysIn("trans_choice(':count open invoice|:count open invoices', \$n)"))
        ->toBe([':count open invoice|:count open invoices']);
});
