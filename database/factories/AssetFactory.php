<?php

namespace Database\Factories;

use App\Enums\AccountSubtype;
use App\Enums\AssetStatus;
use App\Models\Account;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_no' => 'AST-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 6, '0', STR_PAD_LEFT),
            'name' => fake()->words(3, true),
            'description' => null,
            'asset_account_id' => fn () => Account::query()
                ->where('subtype', AccountSubtype::FixedAsset->value)
                ->where('name', '!=', 'Accumulated Depreciation')
                ->orderBy('code')
                ->value('id'),
            'acquired_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'cost_cents' => fake()->numberBetween(50_000, 500_000),
            'salvage_value_cents' => 0,
            'status' => AssetStatus::InService->value,
            'is_active' => true,
        ];
    }

    public function disposed(): static
    {
        return $this->state(fn () => [
            'status' => AssetStatus::Disposed->value,
            'disposed_at' => now()->toDateString(),
        ]);
    }
}
