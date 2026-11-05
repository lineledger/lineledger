<?php

use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Livewire\Attributes\PermissionExempt;
use App\Livewire\Attributes\Requires;
use App\Livewire\Attributes\RequiresSave;
use App\Models\Company;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access\Grant;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;

/**
 * The Livewire method gate checks what a method declares before it runs.
 */
class MethodPermissionGateFixture extends Component
{
    public Company $company;

    public ?int $editingId = null;

    public string $did = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Requires(Activity::Invoices, PermissionAction::Delete)]
    public function void(): void
    {
        $this->did = 'void';
    }

    #[RequiresSave('editingId', Activity::Customers)]
    public function save(): void
    {
        $this->did = 'save';
    }

    #[Requires(Activity::Estimates, PermissionAction::Modify)]
    #[Requires(Activity::Invoices, PermissionAction::Create)]
    public function convert(): void
    {
        $this->did = 'convert';
    }

    #[Requires([Activity::Customers, Activity::Vendors], PermissionAction::Create)]
    public function quickAdd(): void
    {
        $this->did = 'quickAdd';
    }

    #[PermissionExempt('A test of the exemption.')]
    public function preference(): void
    {
        $this->did = 'preference';
    }

    public function undeclared(): void
    {
        $this->did = 'undeclared';
    }

    #[On('fixture-print')]
    #[Requires(Activity::Invoices, PermissionAction::Print)]
    public function printIt(): void
    {
        $this->did = 'print';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->member = User::factory()->create();
    $membership = $this->company->memberships()->create(['user_id' => $this->member->id, 'role' => CompanyRole::Custom, 'sections' => []]);
    $this->role = Role::create(['name' => 'Gate test']);
    MembershipRole::create(['company_member_id' => $membership->id, 'role_id' => $this->role->id]);

    $this->grant = fn (Activity $activity, Grant $grant) => RolePermission::updateOrCreate(
        ['role_id' => $this->role->id, 'activity' => $activity->value],
        RolePermission::attributesFor($activity, $grant),
    );

    $this->page = fn (?User $user = null) => Livewire::actingAs($user ?? $this->member)
        ->test(MethodPermissionGateFixture::class, ['company' => $this->company]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('refuses a declared method until the role allows it', function () {
    ($this->page)()->call('void')->assertForbidden();

    ($this->grant)(Activity::Invoices, Grant::partial(PermissionAction::Delete));

    ($this->page)()->call('void')->assertSet('did', 'void');
});

it('needs Create to add and Modify to change through one save method', function () {
    ($this->grant)(Activity::Customers, Grant::partial(PermissionAction::Create));

    ($this->page)()->call('save')->assertSet('did', 'save');
    ($this->page)()->set('editingId', 5)->call('save')->assertForbidden();

    ($this->grant)(Activity::Customers, Grant::partial(PermissionAction::Modify));

    ($this->page)()->set('editingId', 5)->call('save')->assertSet('did', 'save');
    ($this->page)()->call('save')->assertForbidden();
});

it('needs every repeated declaration', function () {
    ($this->grant)(Activity::Estimates, Grant::full());
    ($this->page)()->call('convert')->assertForbidden();

    ($this->grant)(Activity::Invoices, Grant::partial(PermissionAction::Create));
    ($this->page)()->call('convert')->assertSet('did', 'convert');
});

it('accepts any one of several activities', function () {
    ($this->page)()->call('quickAdd')->assertForbidden();

    ($this->grant)(Activity::Vendors, Grant::partial(PermissionAction::Create));

    ($this->page)()->call('quickAdd')->assertSet('did', 'quickAdd');
});

it('lets exempt and undeclared methods through', function () {
    ($this->page)()->call('preference')->assertSet('did', 'preference')
        ->call('undeclared')->assertSet('did', 'undeclared');
});

it('checks an event listener as the method it runs', function () {
    ($this->page)()->dispatch('fixture-print')->assertForbidden();

    ($this->grant)(Activity::Invoices, Grant::partial(PermissionAction::Print));

    ($this->page)()->dispatch('fixture-print')->assertSet('did', 'print');
});

it('lets the Owner do anything declared', function () {
    $owner = User::factory()->create();
    $this->company->memberships()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);

    ($this->page)($owner)->call('void')->assertSet('did', 'void')
        ->call('convert')->assertSet('did', 'convert');
});
