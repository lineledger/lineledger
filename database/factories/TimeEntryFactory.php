<?php

namespace Database\Factories;

use App\Enums\TimeEntryStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'contact_id' => Contact::factory(),
            'date_worked' => '2025-06-02',
            'hours' => 8,
            'description' => null,
            'billable' => false,
            'status' => TimeEntryStatus::Approved->value,
        ];
    }

    public function billable(): static
    {
        return $this->state(fn () => ['billable' => true]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => TimeEntryStatus::Pending->value]);
    }
}
