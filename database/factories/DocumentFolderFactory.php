<?php

namespace Database\Factories;

use App\Models\DocumentFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentFolder>
 */
class DocumentFolderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * company_id is set by the BelongsToCompany boot hook when current_company
     * is bound; pass it explicitly in tests that don't bind one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'parent_folder_id' => null,
            'viewer_member_ids' => null,
        ];
    }
}
