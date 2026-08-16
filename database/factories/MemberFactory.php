<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'member_no' => 'MEM-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'joined_on' => '2026-01-01',
            'started_on' => '2026-01-01',
            'expires_on' => '2026-12-31',
            'auto_renew' => false,
            'is_active' => true,
        ];
    }
}
