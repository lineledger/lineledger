<?php

namespace Database\Factories;

use App\Enums\CompanyRestoreStatus;
use App\Models\CompanyRestore;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyRestore>
 */
class CompanyRestoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requested_by_user_id' => User::factory(),
            'company_id' => null,
            'status' => CompanyRestoreStatus::Pending,
            'file_path' => null,
            'file_size_bytes' => null,
            'sha256' => null,
            'manifest_data' => null,
            'step_results' => null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
