<?php

use App\Models\User;
use Laravel\Fortify\Features;

/**
 * The site admin portal (/admin) is reachable only by a platform site admin who
 * has two-factor authentication enabled and has re-confirmed their password.
 * EnsureSiteAdmin handles the admin + 2FA gate; the password.confirm middleware
 * adds the re-challenge.
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

it('returns 404 for a non-admin user (portal stays hidden)', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.dashboard'))
        ->assertNotFound();
});

it('redirects a site admin without 2FA to enroll', function () {
    $admin = User::factory()->siteAdmin()->create();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('security.edit'));
});

it('requires password re-confirmation for a 2FA-enabled site admin', function () {
    $admin = User::factory()->siteAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('password.confirm'));
});

it('admits a site admin with 2FA and a confirmed password', function () {
    $admin = User::factory()->siteAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Overview');
});

it('hides the site admin link from the menu for non-admins', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['company' => $user->currentCompany->slug]))
        ->assertOk()
        ->assertDontSee('Site Admin');
});

it('shows the site admin link in the menu for a site admin', function () {
    $admin = User::factory()->siteAdmin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard', ['company' => $admin->currentCompany->slug]))
        ->assertOk()
        ->assertSee('Site Admin');
});
