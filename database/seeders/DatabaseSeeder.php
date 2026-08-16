<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The seeded test user is a site admin with the well-known factory
        // password ('password'), so this seeder must never touch a production
        // database — in production the first registered user is granted the role
        // instead (see App\Actions\Fortify\CreateNewUser).
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'DatabaseSeeder must not run in production — it seeds a known-credential site admin.'
            );
        }

        // User::factory(10)->create();

        // The seeded test user doubles as the platform site admin so the admin
        // portal is reachable in local/QA environments (mirrors how the first
        // registered user is granted the role in production).
        User::factory()->siteAdmin()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
