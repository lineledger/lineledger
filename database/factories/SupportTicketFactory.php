<?php

namespace Database\Factories;

use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => null,
            'subject' => fake()->sentence(6),
            'type' => fake()->randomElement(SupportTicketType::cases()),
            'status' => SupportTicketStatus::Open,
            'last_activity_at' => now(),
        ];
    }

    public function answered(): static
    {
        return $this->state(fn () => ['status' => SupportTicketStatus::Answered]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => SupportTicketStatus::Resolved]);
    }
}
