<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/**
 * Whether the rendered `<ui-switch …>` for the agentic-writes control carries a
 * `disabled` attribute. Matches the attribute token only — Flux's class list
 * contains `[&[disabled]]:…` selectors, so a plain substring check would always hit.
 */
function agenticWritesSwitchDisabled(string $html): bool
{
    preg_match('/<ui-switch[^>]*data-test="company-agentic-writes-input"[^>]*>/', $html, $matches);

    expect($matches)->not->toBeEmpty('the agentic-writes switch was not rendered');

    return (bool) preg_match('/\sdisabled(?=[\s>=\/])/', $matches[0]);
}

it('defaults to agentic writes off', function () {
    expect($this->company->agenticWritesEnabled())->toBeFalse();
});

it('renders the switch enabled when the operator has enabled writes', function () {
    config(['mcp.write_enabled' => true]);

    $component = Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->assertSee('AI assistant writes (MCP)')
        ->assertSee('Allow AI assistant writes')
        ->assertSee('MCP_WRITE_ENABLED')
        ->assertDontSee('The site operator has not enabled AI assistant writes')
        ->assertSet('agenticWrites', false);

    expect(agenticWritesSwitchDisabled($component->html()))->toBeFalse();
});

it('lets an owner opt in and back out', function () {
    config(['mcp.write_enabled' => true]);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('agenticWrites', true)
        ->assertSet('agenticWrites', true);

    expect($this->company->fresh()->agenticWritesEnabled())->toBeTrue();

    // Re-mount from a fresh model: mount() reads the persisted flag, not the
    // stale in-memory instance the first component saved through.
    Livewire::test('pages::companies.edit', ['company' => $this->company->fresh()])
        ->assertSet('agenticWrites', true)
        ->set('agenticWrites', false)
        ->assertSet('agenticWrites', false);

    expect($this->company->fresh()->agenticWritesEnabled())->toBeFalse();
});

it('merges into the existing settings JSON without disturbing other keys', function () {
    config(['mcp.write_enabled' => true]);

    $this->company->setInsightsState(['ai_narration' => true]);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('agenticWrites', true);

    $fresh = $this->company->fresh();

    expect($fresh->agenticWritesEnabled())->toBeTrue()
        ->and($fresh->insightsAiNarrationEnabled())->toBeTrue();
});

it('blocks a custom-role member from flipping the switch', function () {
    config(['mcp.write_enabled' => true]);

    $member = User::factory()->create();
    $this->company->members()->attach($member, ['role' => CompanyRole::Custom->value]);
    $this->actingAs($member);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('agenticWrites', true)
        ->assertForbidden();

    expect($this->company->fresh()->agenticWritesEnabled())->toBeFalse();
});

it('renders the switch disabled with an operator note when the operator has not enabled writes', function () {
    config(['mcp.write_enabled' => false]);

    $component = Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->assertSee('AI assistant writes (MCP)')
        ->assertSee('The site operator has not enabled AI assistant writes on this server');

    expect(agenticWritesSwitchDisabled($component->html()))->toBeTrue();
});

it('refuses to save the switch on when the operator has not enabled writes', function () {
    config(['mcp.write_enabled' => false]);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('agenticWrites', true)
        ->assertSet('agenticWrites', false);

    expect($this->company->fresh()->agenticWritesEnabled())->toBeFalse();
});

it('still lets an owner opt out after the operator has turned writes off', function () {
    $this->company->setMcpState(['agentic_writes' => true]);
    config(['mcp.write_enabled' => false]);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->assertSet('agenticWrites', true)
        ->set('agenticWrites', false)
        ->assertSet('agenticWrites', false);

    expect($this->company->fresh()->agenticWritesEnabled())->toBeFalse();
});
