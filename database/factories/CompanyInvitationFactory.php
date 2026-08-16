<?php

namespace Database\Factories;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyInvitation>
 */
class CompanyInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => CompanyRole::Accountant,
            'invited_by' => User::factory(),
            'expires_at' => null,
            'accepted_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expiresIn(int $value, string $unit = 'days'): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->add($unit, $value),
        ]);
    }
}
