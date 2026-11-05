<?php

namespace App\Livewire\Attributes;

use App\Enums\Activity;
use Attribute;

/**
 * For a save method that both adds and changes records, depending on whether
 * the form is editing one: Create while the named property is empty, Modify
 * once it holds a record (or its id).
 *
 *     #[RequiresSave('editingId')]
 *     public function save(): void
 *
 * A null activity means the page's own.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequiresSave
{
    /** @var list<Activity> empty: the page's own */
    public array $activities;

    public function __construct(
        public string $record,
        ?Activity $activity = null,
    ) {
        $this->activities = $activity === null ? [] : [$activity];
    }
}
