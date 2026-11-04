<?php

namespace App\Models;

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Services\Access\PermissionResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

/**
 * @property CompanyRole $role
 * @property list<string>|null $sections section values, for the Custom role only
 */
#[Fillable(['company_id', 'user_id', 'role', 'sections'])]
class Membership extends Pivot
{
    /**
     * @var string
     */
    protected $table = 'company_members';

    /**
     * @var bool
     */
    public $incrementing = true;

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The sections this member can access.
     *
     * For preset roles the section set comes from the role; for the Custom role
     * it comes from the stored `sections` selection.
     *
     * @return Collection<int, Section>
     */
    public function effectiveSections(): Collection
    {
        if (! $this->role->usesCustomSections()) {
            return collect($this->role->sections());
        }

        return collect($this->sections ?? [])
            ->map(fn (string $value) => Section::tryFrom($value))
            ->filter()
            ->values();
    }

    /**
     * Determine whether this member can open anything in the section (area):
     * from the roles they hold, or their original role until converted.
     */
    public function canAccessSection(Section $section): bool
    {
        return app(PermissionResolver::class)->forMembership($this)->canAccessArea($section);
    }

    /**
     * The Custom role's section values, however they were stored: a pivot
     * attach() bypasses the array cast, so a caller that JSON-encoded the
     * list leaves it double-encoded. Unknown shapes read as no sections.
     *
     * @return list<string>
     */
    public function sectionValues(): array
    {
        $value = $this->getAttributeValue('sections');

        while (is_string($value)) {
            $decoded = json_decode($value, true);

            if ($decoded === null || $decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'sections' => 'array',
        ];
    }
}
