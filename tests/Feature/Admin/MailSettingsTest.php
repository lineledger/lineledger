<?php

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * A one-click install boots with SMTP selected and no host. Mail must not break
 * anything in that state, the first account must still get in, and the site
 * admin sets mail up from /admin/mail instead of editing the environment.
 */
beforeEach(function () {
    Cache::forget('site_settings');
});

function withoutMailServer(): void
{
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.mailers.smtp.url' => null]);
}

function mailSettingsAdmin(): User
{
    return User::factory()->siteAdmin()->withTwoFactor()->create(['email' => 'admin@example.com']);
}

function registerFirstUser(): void
{
    test()->post(route('register.store'), [
        'name' => 'First User',
        'email' => 'first@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertSessionHasNoErrors();
}

it('treats SMTP with no host as not configured, and a real mailer as configured', function () {
    expect(MailSettings::configured())->toBeTrue(); // the suite's array mailer

    withoutMailServer();

    expect(MailSettings::configured())->toBeFalse();
});

it('writes mail to the log instead of failing when there is no mail server', function () {
    withoutMailServer();
    app()->forgetInstance('mail.manager');

    Mail::raw('hello', fn ($message) => $message->to('someone@example.com'));

    expect(config('mail.default'))->toBe('log')
        ->and(MailSettings::configured())->toBeFalse();
});

it('lets the first account in without verifying when there is no mail server', function () {
    withoutMailServer();

    registerFirstUser();

    $user = User::where('email', 'first@example.com')->sole();
    expect($user->site_admin)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

it('still makes the first account verify when mail works', function () {
    registerFirstUser();

    expect(User::where('email', 'first@example.com')->sole()->hasVerifiedEmail())->toBeFalse();
});

it('makes later accounts verify even without a mail server', function () {
    User::factory()->create();
    withoutMailServer();

    test()->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'second@example.com')->sole()->hasVerifiedEmail())->toBeFalse();
});

it('saves SMTP settings from /admin/mail, with the password encrypted at rest', function () {
    withoutMailServer();
    $this->actingAs(mailSettingsAdmin());

    Livewire::test('pages::admin.mail')
        ->set('host', 'smtp.postmarkapp.com')
        ->set('port', '587')
        ->set('username', 'token')
        ->set('password', 's3cret-pass')
        ->set('fromAddress', 'books@example.com')
        ->set('fromName', 'Acme Books')
        ->call('save')
        ->assertHasNoErrors();

    expect(MailSettings::saved())->toMatchArray([
        'host' => 'smtp.postmarkapp.com',
        'port' => 587,
        'username' => 'token',
        'password' => 's3cret-pass',
        'from_address' => 'books@example.com',
        'from_name' => 'Acme Books',
    ])->and(MailSettings::configured())->toBeTrue();

    $raw = SiteSetting::query()->where('key', 'mail')->value('value');
    expect($raw['password'])->not->toBe('s3cret-pass');

    MailSettings::apply();
    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.postmarkapp.com')
        ->and(config('mail.from.address'))->toBe('books@example.com');
});

it('keeps the saved password when the field is left blank', function () {
    $this->actingAs(mailSettingsAdmin());
    MailSettings::save([
        'host' => 'smtp.example.com', 'port' => 587, 'username' => 'u', 'password' => 'original',
        'from_address' => 'books@example.com', 'from_name' => 'Books',
    ]);

    Livewire::test('pages::admin.mail')
        ->assertSet('password', '')
        ->set('host', 'smtp2.example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect(MailSettings::saved()['password'])->toBe('original')
        ->and(MailSettings::saved()['host'])->toBe('smtp2.example.com');
});

it('validates the settings', function () {
    $this->actingAs(mailSettingsAdmin());

    Livewire::test('pages::admin.mail')
        ->set('host', '')
        ->set('port', '70000')
        ->set('fromAddress', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['host', 'port', 'fromAddress']);

    expect(MailSettings::saved())->toBeNull();
});

it('sends a test email to the signed-in admin', function () {
    $this->actingAs(mailSettingsAdmin());

    Livewire::test('pages::admin.mail')->call('sendTest');

    $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1)
        ->and($sent->first()->getEnvelope()->getRecipients()[0]->getAddress())->toBe('admin@example.com');
});

it('does not pretend to send a test email when there is no mail server', function () {
    withoutMailServer();
    Mail::fake();
    $this->actingAs(mailSettingsAdmin());

    Livewire::test('pages::admin.mail')->call('sendTest');

    Mail::assertNothingOutgoing();
});

it('removes saved settings', function () {
    $this->actingAs(mailSettingsAdmin());
    MailSettings::save([
        'host' => 'smtp.example.com', 'port' => 587, 'username' => null, 'password' => null,
        'from_address' => 'books@example.com', 'from_name' => 'Books',
    ]);

    Livewire::test('pages::admin.mail')->call('forget')->assertRedirect(route('admin.mail'));

    expect(MailSettings::saved())->toBeNull();
});

it('hides /admin/mail from anyone but a site admin', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.mail'))
        ->assertNotFound();
});

it('shows site admins a banner until mail is set up', function () {
    $admin = mailSettingsAdmin();
    $member = User::factory()->withTwoFactor()->create();
    withoutMailServer();

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('data-test="mail-not-configured-banner"', false)
        ->assertSee('data-test="mail-banner-set-up"', false);

    $this->actingAs($member)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('data-test="mail-not-configured-banner"', false);

    MailSettings::save([
        'host' => 'smtp.example.com', 'port' => 587, 'username' => null, 'password' => null,
        'from_address' => 'books@example.com', 'from_name' => 'Books',
    ]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('admin.dashboard'))
        ->assertDontSee('data-test="mail-not-configured-banner"', false);
});

it('sends a site admin without two-factor authentication to turn it on before setting up email', function () {
    // The site admin area, /admin/mail included, needs 2FA (EnsureSiteAdmin), so
    // the banner points at that step instead of a link that bounces.
    $admin = User::factory()->siteAdmin()->create();
    withoutMailServer();

    $this->actingAs($admin)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('data-test="mail-banner-enable-2fa"', false)
        ->assertSee(route('security.edit'), false)
        ->assertDontSee('data-test="mail-banner-set-up"', false);
});
