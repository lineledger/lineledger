<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\App;

/**
 * Both seeders create a `test@example.com` / `password` platform site admin,
 * so they must refuse to run against a production database (a launch backdoor
 * if `migrate --seed` were ever run there). In production the site admin is
 * instead bootstrapped by the first registered user.
 */
it('refuses to run DatabaseSeeder in production', function () {
    App::detectEnvironment(fn () => 'production');

    expect(fn () => (new DatabaseSeeder)->run())
        ->toThrow(RuntimeException::class, 'must not run in production');

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});

it('refuses to run DemoCompanySeeder in production', function () {
    App::detectEnvironment(fn () => 'production');

    expect(fn () => (new DemoCompanySeeder)->run())
        ->toThrow(RuntimeException::class, 'must not run in production');
});

it('seeds the demo site admin outside production', function () {
    // Sanity: the guard only blocks production, dev/QA seeding still works.
    expect(App::environment('production'))->toBeFalse();

    (new DatabaseSeeder)->run();

    $admin = User::query()->where('email', 'test@example.com')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->site_admin)->toBeTrue();
});
