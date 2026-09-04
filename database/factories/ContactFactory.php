<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => fake()->unique()->company(),
            'company_name' => fake()->company(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'is_customer' => false,
            'is_vendor' => false,
            'is_employee' => false,
            'is_donor' => false,
            'is_other_name' => false,
            'is_active' => true,
            'invoice_emails_enabled' => false,
            'reminder_emails_enabled' => false,
        ];
    }

    public function vendor(): static
    {
        return $this->state(fn () => ['is_vendor' => true]);
    }

    public function customer(): static
    {
        return $this->state(fn () => ['is_customer' => true]);
    }

    public function donor(): static
    {
        return $this->state(fn () => ['is_donor' => true]);
    }

    /**
     * A QuickBooks-style "Other name": a one-time payee with no directory role.
     */
    public function otherName(): static
    {
        return $this->state(fn () => ['is_other_name' => true]);
    }

    /**
     * Consents to both automated invoice emails and payment reminders — the state
     * a user reaches by turning on both switches on the customer's billing tab.
     */
    public function emailOptedIn(): static
    {
        return $this->state(fn () => [
            'invoice_emails_enabled' => true,
            'reminder_emails_enabled' => true,
        ]);
    }
}
