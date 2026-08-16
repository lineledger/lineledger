<?php

namespace Database\Factories;

use App\Enums\BankStatementFormat;
use App\Models\BankImportProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankImportProfile>
 */
class BankImportProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' Chequing',
            'source_format' => BankStatementFormat::Csv->value,
            'mapping' => [
                'amountMode' => 'single',
                'dateColumn' => 'date',
                'descriptionColumns' => ['description'],
                'amountColumn' => 'amount',
                'dateFormat' => 'Y-m-d',
                'decimalSeparator' => '.',
                'flipSign' => false,
            ],
            'header_signature' => null,
            'usage_count' => 0,
        ];
    }
}
