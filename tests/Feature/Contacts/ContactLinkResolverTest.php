<?php

use App\Actions\Contacts\SaveOtherName;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\Contacts\ContactLinkResolver;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->resolver = app(ContactLinkResolver::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * A Custom-role member limited to the given sections (null = every section).
 */
function contactLinkMember(Company $company, ?array $sections): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => $sections,
    ]);

    return $user;
}

it('routes each role to its home page', function () {
    $slug = $this->company->slug;
    $customer = Contact::factory()->customer()->create();
    $vendor = Contact::factory()->vendor()->create();
    $both = Contact::factory()->customer()->vendor()->create();
    $employee = Contact::factory()->create(['is_employee' => true]);
    $other = Contact::factory()->otherName()->create();
    $flagless = Contact::factory()->create();

    expect($this->resolver->urlFor($customer, $this->company))
        ->toBe(route('reports.contact-statement', ['company' => $slug, 'contact' => $customer->id, 'kind' => 'ar']))
        ->and($this->resolver->urlFor($vendor, $this->company))
        ->toBe(route('reports.contact-statement', ['company' => $slug, 'contact' => $vendor->id, 'kind' => 'ap']))
        // A customer who is also a vendor lands on the AR statement, like global search today.
        ->and($this->resolver->urlFor($both, $this->company))
        ->toBe(route('reports.contact-statement', ['company' => $slug, 'contact' => $both->id, 'kind' => 'ar']))
        ->and($this->resolver->urlFor($employee, $this->company))
        ->toBe(route('employees.index', ['company' => $slug, 'edit' => $employee->id]))
        ->and($this->resolver->urlFor($other, $this->company))
        ->toBe($this->resolver->transactionsUrl($other, $this->company))
        ->and($this->resolver->urlFor($flagless, $this->company))
        ->toBe($this->resolver->transactionsUrl($flagless, $this->company));
});

it('sends other names to the transactions report over an all-time range', function () {
    $other = Contact::factory()->otherName()->create();
    $today = $this->company->currentDateTime()->toDateString();

    $url = $this->resolver->transactionsUrl($other, $this->company);

    expect($url)->toBe(route('reports.transactions', [
        'company' => $this->company->slug,
        'contact' => $other->id,
        'range' => 'all',
        'start' => '1970-01-01',
        'end' => $today,
    ]))
        ->and($this->resolver->transactionsUrl($other->id, $this->company))->toBe($url);
});

it('renders a link only when the viewer can reach the target section', function () {
    $vendor = Contact::factory()->vendor()->create();
    $employee = Contact::factory()->create(['is_employee' => true]);
    $other = Contact::factory()->otherName()->create();

    $vendorsOnly = contactLinkMember($this->company, [Section::Vendors->value]);
    $bankingOnly = contactLinkMember($this->company, [Section::Banking->value]);
    $reportsOnly = contactLinkMember($this->company, [Section::Reports->value]);
    $employeesOnly = contactLinkMember($this->company, [Section::Employees->value]);

    // Statements and the transactions report are reports.* routes, so the
    // guard follows the TARGET route's section, not the contact's role.
    expect($this->resolver->urlForViewer($vendor, $this->company, $this->user))->not->toBeNull()
        ->and($this->resolver->urlForViewer($vendor, $this->company, $vendorsOnly))->toBeNull()
        ->and($this->resolver->urlForViewer($vendor, $this->company, $reportsOnly))->not->toBeNull()
        ->and($this->resolver->urlForViewer($other, $this->company, $bankingOnly))->toBeNull()
        ->and($this->resolver->urlForViewer($other, $this->company, $reportsOnly))
        ->toBe($this->resolver->transactionsUrl($other, $this->company))
        ->and($this->resolver->urlForViewer($employee, $this->company, $employeesOnly))->not->toBeNull()
        ->and($this->resolver->urlForViewer($employee, $this->company, $reportsOnly))->toBeNull()
        ->and($this->resolver->urlForViewer($other, $this->company, null))->toBeNull()
        ->and($this->resolver->transactionsUrlForViewer($other, $this->company, $bankingOnly))->toBeNull()
        ->and($this->resolver->transactionsUrlForViewer($other, $this->company, $reportsOnly))->not->toBeNull();
});

it('lists every held role as a badge and summarises it for search', function () {
    $other = Contact::factory()->otherName()->create();
    $both = Contact::factory()->customer()->vendor()->create();
    $flagless = Contact::factory()->create();

    expect(array_column($this->resolver->roleLabels($other), 'label'))->toBe(['Other name'])
        ->and($this->resolver->roleLabels($other)[0]['color'])->toBe('violet')
        ->and(array_column($this->resolver->roleLabels($both), 'label'))->toBe(['Customer', 'Vendor'])
        ->and($this->resolver->roleLabel($both))->toBe('customer, vendor')
        ->and($this->resolver->roleLabel($other))->toBe('other name')
        ->and($this->resolver->roleLabel($flagless))->toBe('contact');
});

it('memoises the membership lookup per viewer, company and route', function () {
    $others = Contact::factory()->otherName()->count(3)->create();
    $reportsOnly = contactLinkMember($this->company, [Section::Reports->value]);

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        // The membership lookup is the only query in this loop that filters on user_id.
        if (str_contains($query->sql, 'user_id')) {
            $queries++;
        }
    });

    foreach ($others as $other) {
        expect($this->resolver->urlForViewer($other, $this->company, $reportsOnly))->not->toBeNull();
    }

    expect($queries)->toBe(1);
});

it('refuses to edit a contact that is not an other name', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supplies', 'email' => 'ap@acme.example']);

    expect(fn () => app(SaveOtherName::class)->handle(['display_name' => 'Renamed'], $vendor))
        ->toThrow(ValidationException::class);

    expect($vendor->fresh()->display_name)->toBe('Acme Supplies');
});

it('renames an other name without touching its other columns, and creates through the other-name role', function () {
    $other = Contact::factory()->otherName()->create(['display_name' => 'J. Chen', 'email' => 'jc@example.test']);

    app(SaveOtherName::class)->handle(['display_name' => 'Jane Chen', 'notes' => 'Raffle', 'is_active' => false], $other);

    $other->refresh();
    expect($other->display_name)->toBe('Jane Chen')
        ->and($other->notes)->toBe('Raffle')
        ->and($other->is_active)->toBeFalse()
        ->and($other->email)->toBe('jc@example.test');

    $created = app(SaveOtherName::class)->handle(['display_name' => 'Walk-in refund']);

    expect($created->is_other_name)->toBeTrue()
        ->and($created->hasDirectoryRole())->toBeFalse()
        ->and($created->is_active)->toBeTrue()
        ->and($created->company_id)->toBe($this->company->id);
});

it('hides the link when the site admin has switched the target section off', function () {
    $other = Contact::factory()->otherName()->create();

    expect($this->resolver->urlForViewer($other, $this->company, $this->user))->not->toBeNull();

    SiteSettings::set('disabled_sections', [Section::Reports->value]);

    expect(app(ContactLinkResolver::class)->urlForViewer($other, $this->company, $this->user))->toBeNull()
        ->and(app(ContactLinkResolver::class)->transactionsUrlForViewer($other, $this->company, $this->user))->toBeNull();
});
