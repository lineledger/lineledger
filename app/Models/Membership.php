<?php

namespace App\Models;

use App\Enums\CompanyRole;
use App\Enums\Section;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

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
     * Determine whether this member can access the given section.
     */
    public function canAccessSection(Section $section): bool
    {
        if ($this->role === CompanyRole::Owner) {
            return true;
        }

        return $this->effectiveSections()->contains($section);
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
