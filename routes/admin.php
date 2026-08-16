<?php

use App\Http\Middleware\EnsureSiteAdmin;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Site admin portal
|--------------------------------------------------------------------------
|
| Platform-level (cross-tenant) screens for the operator: site-wide feature
| toggles and read-only lists of every user and company. Deliberately outside
| the {company} prefix — these pages are not scoped to a tenant. EnsureSiteAdmin
| restricts access to site admins and requires 2FA; password.confirm forces a
| password re-challenge on entry. (The security and company settings pages use
| the stronger `2fa.confirm` step-up gate instead — see routes/settings.php.)
|
*/

$confirmsPassword = Features::canManageTwoFactorAuthentication()
    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword')
        ? ['password.confirm']
        : [];

Route::middleware(['auth', 'verified', EnsureSiteAdmin::class, ...$confirmsPassword])
    ->prefix('admin')
    ->group(function () {
        Route::livewire('/', 'pages::admin.dashboard')->name('admin.dashboard');
        Route::livewire('security', 'pages::admin.security')->name('admin.security');
        Route::livewire('settings', 'pages::admin.settings')->name('admin.settings');
        Route::livewire('users', 'pages::admin.users')->name('admin.users');
        Route::livewire('support', 'pages::admin.support-tickets')->name('admin.support');
        Route::livewire('support/{ticket}', 'pages::admin.support-ticket-show')->name('admin.support.show');
        Route::livewire('companies', 'pages::admin.companies')->name('admin.companies');
        Route::livewire('companies/{company}', 'pages::admin.company-show')
            ->name('admin.companies.show')
            ->withTrashed();
    });
