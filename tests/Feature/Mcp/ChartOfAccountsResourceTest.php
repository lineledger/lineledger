<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Mcp\Concerns\ProposesWrites;
use App\Mcp\Resources\ChartOfAccountsResource;
use App\Mcp\Tools\ChartOfAccountsTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\Gifi\GifiCatalog;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;

/**
 * The chart-of-accounts resource (and its companion tool) lists every account
 * with its code, GIFI code/label, and reporting balance. OAuth access requires
 * the Accounting section.
 */
afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
    Auth::forgetGuards();
});

it('ChartOfAccounts: resource lists seeded accounts with code and GIFI label', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    $gifiAccount = Account::query()->whereNotNull('gifi_code')->orderBy('code')->firstOrFail();
    $gifiLabel = GifiCatalog::find($gifiAccount->gifi_code)['label'];

    $response = (new ChartOfAccountsResource)->handle(new Request([]));
    $content = (string) $response->content();

    expect($response->isError())->toBeFalse()
        ->and($content)->toContain('Chart of accounts')
        ->and($content)->toContain($gifiAccount->code)
        ->and($content)->toContain($gifiAccount->name)
        ->and($content)->toContain("GIFI {$gifiAccount->gifi_code}")
        ->and($content)->toContain($gifiLabel);
});

it('ChartOfAccounts: exposes each account\'s numeric API id alongside its code', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    // A code the company can renumber vs. the surrogate id integrations hardcode.
    $account = Account::query()->orderBy('code')->firstOrFail();

    $content = (string) (new ChartOfAccountsResource)->handle(new Request([]))->content();

    expect($content)->toContain("API id {$account->id}")
        // The id is labelled, so it can't be mistaken for the account code.
        ->and($content)->toContain('"API id" is the stable numeric account_id');
});

it('ChartOfAccounts: the API id is the id the write tools resolve an account by', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    $account = Account::query()->orderBy('code')->firstOrFail();

    $content = (string) (new ChartOfAccountsTool)->handle(new Request([]))->content();

    // Pull the id straight back out of the rendered text the way a client would,
    // then prove it round-trips through the resolver the propose-* tools use.
    preg_match('/'.preg_quote($account->name, '/').'.*?API id (\d+)/', $content, $m);

    expect($m[1] ?? null)->not->toBeNull();

    $resolver = new class
    {
        use ProposesWrites;

        public function resolve(mixed $ref): ?Account
        {
            return $this->resolveAccount($ref);
        }
    };

    expect($resolver->resolve($m[1])?->id)->toBe($account->id);
});

it('ChartOfAccounts: companion tool returns the same chart text', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    $resourceText = (string) (new ChartOfAccountsResource)->handle(new Request([]))->content();
    $toolText = (string) (new ChartOfAccountsTool)->handle(new Request([]))->content();

    expect($toolText)->toBe($resourceText);
});

it('ChartOfAccounts: denies an OAuth member without the Accounting section', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Banking->value],
    ]);
    app()->instance('current_company', $company);
    Auth::guard('api')->setUser($user);

    $response = (new ChartOfAccountsResource)->handle(new Request([]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('do not have access');
});

it('ChartOfAccounts: allows an OAuth member granted the Accounting section', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Accounting->value],
    ]);
    app()->instance('current_company', $company);
    Auth::guard('api')->setUser($user);

    expect((new ChartOfAccountsResource)->handle(new Request([]))->isError())->toBeFalse();
});
