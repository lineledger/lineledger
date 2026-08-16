<?php

use App\Http\Controllers\Auth\TwoFactorConfirmationController;
use App\Http\Middleware\EnsureCompanyMembership;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');

    Route::livewire('settings/legal', 'pages::settings.legal')->name('legal.edit');

    Route::livewire('settings/navigation', 'pages::settings.navigation')->name('navigation.edit');

    // Step-up 2FA confirmation for the sensitive settings pages below. Lives
    // outside the `2fa.confirm` gate (confirming here is how you pass it), and
    // keeps a distinct name from Fortify's enable-time `two-factor.confirm`.
    Route::get('two-factor-confirm', [TwoFactorConfirmationController::class, 'show'])
        ->name('two-factor.reconfirm');
    Route::post('two-factor-confirm', [TwoFactorConfirmationController::class, 'store'])
        ->middleware('throttle:two-factor-confirm')
        ->name('two-factor.reconfirm.store');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    // Step-up gate: a user with 2FA must re-confirm with a fresh 2FA code
    // (even on a "remembered" device); a user without 2FA falls back to
    // password confirmation, so this page stays reachable to enable 2FA.
    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware('2fa.confirm')
        ->name('security.edit');

    Route::livewire('settings/companies', 'pages::companies.index')->name('companies.index');

    // Document inbox email: enable inbound email + display/rotate the forwarding
    // address, and the per-company receipt-OCR opt-in.
    Route::livewire('settings/inbox-email', 'pages::settings.inbox-email')->name('inbox-email.edit');

    Route::middleware([EnsureCompanyMembership::class, '2fa.confirm'])->group(function () {
        Route::livewire('settings/companies/{company}', 'pages::companies.edit')->name('companies.edit');
    });

    // Combined multi-company reports (cross-tenant — no {company} prefix).
    Route::livewire('settings/report-groups', 'pages::report-groups.index')->name('report-groups.index');
    Route::livewire('settings/report-groups/{reportGroup}/edit', 'pages::report-groups.edit')->name('report-groups.edit');

    Route::livewire('report-groups/{reportGroup}/income-statement', 'pages::report-groups.income-statement')->name('report-groups.income-statement');
    Route::livewire('report-groups/{reportGroup}/income-statement/sections', 'pages::report-groups.income-statement-sections')->name('report-groups.income-statement.sections');
    Route::livewire('report-groups/{reportGroup}/balance-sheet', 'pages::report-groups.balance-sheet')->name('report-groups.balance-sheet');
    Route::livewire('report-groups/{reportGroup}/balance-sheet/sections', 'pages::report-groups.balance-sheet-sections')->name('report-groups.balance-sheet.sections');
    Route::livewire('report-groups/{reportGroup}/cash-flow', 'pages::report-groups.cash-flow')->name('report-groups.cash-flow');
    Route::livewire('report-groups/{reportGroup}/cash-flow/sections', 'pages::report-groups.cash-flow-sections')->name('report-groups.cash-flow.sections');
    Route::livewire('report-groups/{reportGroup}/trial-balance', 'pages::report-groups.trial-balance')->name('report-groups.trial-balance');
});
