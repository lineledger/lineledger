<?php

namespace Database\Factories;

use App\Models\FormStyle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormStyle>
 */
class FormStyleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'is_default' => false,
            'show_logo' => true,
            'accent_color' => null,
            'footer_message' => null,
            'is_active' => true,
        ];
    }
}
