<?php

namespace Database\Factories;

use App\Enums\CompanyBackupStatus;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyBackup>
 */
class CompanyBackupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'requested_by_user_id' => User::factory(),
            'status' => CompanyBackupStatus::Pending,
            'file_path' => null,
            'file_size_bytes' => null,
            'sha256' => null,
            'row_counts' => null,
            'app_version' => '1.0.0',
            'schema_version' => 1,
            'error_message' => null,
            'expires_at' => null,
        ];
    }
}
