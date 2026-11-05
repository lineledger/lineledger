<?php

namespace App\Livewire\Attributes;

use Attribute;

/**
 * Marks a public method that writes but needs no permission beyond opening
 * the page — a personal preference (a memorized report, a dismissed banner),
 * not the organization's records. The reason is required and shows up in
 * review; the method-permission coverage test accepts it.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class PermissionExempt
{
    public function __construct(public string $reason) {}
}
