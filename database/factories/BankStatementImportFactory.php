<?php

namespace Database\Factories;

use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Models\BankStatementImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankStatementImport>
 */
class BankStatementImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_format' => BankStatementFormat::Csv->value,
            'original_filename' => 'statement.csv',
            'status' => BankStatementImportStatus::Ready->value,
            'statement_end_date' => now()->endOfMonth()->toDateString(),
            'statement_end_balance_cents' => 0,
            'line_count' => 0,
            'matched_count' => 0,
            'created_count' => 0,
            'duplicate_count' => 0,
        ];
    }

    public function committed(): static
    {
        return $this->state(fn () => ['status' => BankStatementImportStatus::Committed->value]);
    }

    public function ofx(): static
    {
        return $this->state(fn () => [
            'source_format' => BankStatementFormat::Ofx->value,
            'original_filename' => 'statement.ofx',
        ]);
    }
}
