<?php

use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\SecurityLog;
use App\Models\User;
use Livewire\Livewire;

/**
 * The switcher opens the chosen company in a new tab: each other company is a
 * target="_blank" form posting to companies.switch, which redirects that tab to
 * the chosen company's dashboard — never the page the user was on, whose
 * record ids belong to the company being left.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->from = Company::factory()->create();
    $this->to = Company::factory()->create();

    foreach ([$this->from, $this->to] as $company) {
        $company->members()->attach($this->user, ['role' => CompanyRole::Accountant->value]);
    }

    $this->user->switchCompany($this->from);
});

test('the switcher opens every other company in a new tab', function () {
    $this->actingAs($this->user);

    Livewire::test('company-switcher')
        ->assertSeeHtml('action="'.route('companies.switch', $this->to->slug).'" target="_blank"')
        ->assertDontSeeHtml('action="'.route('companies.switch', $this->from->slug).'"');
});

test('switching opens the chosen company dashboard, not the current page', function (string $page) {
    $this->actingAs($this->user)
        ->withHeader('Referer', url("/{$this->from->slug}/{$page}"))
        ->post(route('companies.switch', $this->to->slug), ['from' => $this->from->slug])
        ->assertRedirect(route('dashboard', ['company' => $this->to->slug]));

    expect($this->user->fresh()->current_company_id)->toBe($this->to->id);
})->with([
    'a list page' => ['invoices?status=open'],
    // A record of the company being left — rewriting it into the new company
    // would 404.
    'a record page' => ['reports/contact-statement/880?kind=ar'],
]);

test('the security log records the tab\'s company, not the last-used one', function () {
    // Another tab loaded a third company since this tab rendered.
    $other = Company::factory()->create();
    $other->members()->attach($this->user, ['role' => CompanyRole::Accountant->value]);
    $this->user->switchCompany($other);

    $this->actingAs($this->user)
        ->withHeader('Referer', url("/{$this->from->slug}/invoices"))
        ->post(route('companies.switch', $this->to->slug), ['from' => $this->from->slug])
        ->assertRedirect(route('dashboard', ['company' => $this->to->slug]));

    $row = SecurityLog::query()->where('event', SecurityEvent::CompanySwitched)->latest('id')->first();

    expect($row->metadata['from_company_slug'])->toBe($this->from->slug)
        ->and($row->metadata['to_company_slug'])->toBe($this->to->slug);
});

test('a from company the user does not belong to is ignored', function () {
    $foreign = Company::factory()->create();

    $this->actingAs($this->user)
        ->withHeader('Referer', url("/{$this->from->slug}/invoices"))
        ->post(route('companies.switch', $this->to->slug), ['from' => $foreign->slug])
        ->assertRedirect(route('dashboard', ['company' => $this->to->slug]));

    $row = SecurityLog::query()->where('event', SecurityEvent::CompanySwitched)->latest('id')->first();

    expect($row->metadata['from_company_slug'])->toBe($this->from->slug);
});

test('switching to the current company records no event', function () {
    $this->actingAs($this->user)
        ->post(route('companies.switch', $this->from->slug), ['from' => $this->from->slug])
        ->assertRedirect();

    expect(SecurityLog::query()->where('event', SecurityEvent::CompanySwitched)->exists())->toBeFalse();
});

test('the new tab opens the chosen company dashboard whatever the referer', function (?string $referer) {
    $request = $this->actingAs($this->user);

    if ($referer !== null) {
        $request = $request->withHeader('Referer', $referer);
    }

    $request->post(route('companies.switch', $this->to->slug), ['from' => $this->from->slug])
        ->assertRedirect(route('dashboard', ['company' => $this->to->slug]));
})->with([
    'no referer' => [null],
    'another site' => ['https://evil.example/'],
    'a lookalike host' => ['http://localhost.evil.example/'],
]);

test('a non-member cannot switch to a company', function () {
    $foreign = Company::factory()->create();

    $this->actingAs($this->user)
        ->post(route('companies.switch', $foreign->slug), ['from' => $this->from->slug])
        ->assertForbidden();

    expect($this->user->fresh()->current_company_id)->toBe($this->from->id);
});

test('a guest is sent to log in', function () {
    $this->post(route('companies.switch', $this->to->slug))
        ->assertRedirect(route('login'));
});
