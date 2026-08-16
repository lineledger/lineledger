<?php

use App\Models\User;

/**
 * The `guest` middleware redirects an already-authenticated user away from
 * guest-only pages (login, register, password reset). The framework default
 * resolves the named `dashboard` route, whose URI is `{company}/dashboard` —
 * generating it throws UrlGenerationException when the user has no current
 * company. FortifyServiceProvider overrides the target with a company-aware
 * path; these cover both the has-company and no-company branches.
 */
test('an authenticated user with a company is redirected from register to their dashboard', function () {
    $user = User::factory()->create();
    $slug = $user->currentCompany->slug;

    $this->actingAs($user)
        ->get(route('register'))
        ->assertRedirect("/{$slug}/dashboard");
});

test('an authenticated user with a company is redirected from login to their dashboard', function () {
    $user = User::factory()->create();
    $slug = $user->currentCompany->slug;

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect("/{$slug}/dashboard");
});

test('an authenticated user with no company is redirected from register to the welcome wizard', function () {
    // Mirrors the production failure: current_company_id is null and the user
    // has no personal company, so the company URL default is never set.
    $user = User::factory()->create();
    $user->companies()->detach();
    $user->forceFill(['current_company_id' => null])->save();

    // fresh() drops the currentCompany relation the factory cached via
    // switchCompany(), so the request sees the user as it loads in production.
    $this->actingAs($user->fresh())
        ->get(route('register'))
        ->assertRedirect(route('welcome.create-company', absolute: false));
});
