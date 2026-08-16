<?php

namespace Database\Factories;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicketMessage>
 */
class SupportTicketMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_ticket_id' => SupportTicket::factory(),
            'user_id' => fn (array $attrs) => SupportTicket::find($attrs['support_ticket_id'])?->user_id
                ?? SupportTicket::factory()->create()->user_id,
            'from_admin' => false,
            'body' => fake()->paragraph(),
            'read_at' => null,
        ];
    }

    public function fromAdmin(): static
    {
        return $this->state(fn () => ['from_admin' => true]);
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }
}
