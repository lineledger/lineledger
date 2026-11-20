<?php

use App\Actions\Lists\DeleteListRecord;
use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Enums\FundType;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Classification;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\Fund;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\MemorizedReport;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->owner);
    $this->action = app(DeleteListRecord::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function listDeleteMessages(Throwable $e): array
{
    return $e instanceof DeletionBlockedException ? array_map(fn ($b) => $b->message, $e->blockers) : [$e->getMessage()];
}

it('deletes an inactive record nothing uses, and keeps a copy in the audit log', function () {
    $class = Classification::create(['name' => 'Retired', 'is_active' => false]);

    $this->action->handle($class, $this->owner);

    $audit = AccountingAuditLog::query()->latest('id')->first();

    expect(Classification::query()->whereKey($class->id)->exists())->toBeFalse()
        ->and($audit->action)->toBe(AuditAction::ListRecordDeleted)
        ->and($audit->payload['record']['name'])->toBe('Retired')
        ->and(Artisan::call('audit:verify', ['company' => $this->company->id, '--full' => true, '--no-alert' => true]))->toBe(0);
});

it('asks for the record to be made inactive first', function () {
    $class = Classification::create(['name' => 'In use', 'is_active' => true]);

    expect(fn () => $this->action->handle($class, $this->owner))->toThrow(DeletionBlockedException::class);
    expect(Classification::query()->whereKey($class->id)->exists())->toBeTrue();

    $this->action->handle($class, $this->owner, requireInactive: false);

    expect(Classification::query()->whereKey($class->id)->exists())->toBeFalse();
});

it('refuses a record a document still uses, naming where', function () {
    $item = Item::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $invoice = Invoice::create(['contact_id' => $customer->id, 'invoice_no' => 'INV-X1', 'invoice_date' => '2026-02-01', 'due_date' => '2026-02-01']);
    $invoice->lines()->create([
        'account_id' => Account::query()->where('subtype', AccountSubtype::Income->value)->value('id'),
        'item_id' => $item->id, 'description' => 'Widget', 'quantity' => '1', 'unit_price_cents' => 100,
        'line_subtotal_cents' => 100, 'line_tax_cents' => 0, 'line_total_cents' => 100, 'line_order' => 0,
    ]);

    try {
        $this->action->handle($item, $this->owner);
        $this->fail('The delete was not refused.');
    } catch (DeletionBlockedException $e) {
        expect(implode(' ', listDeleteMessages($e)))->toContain('invoice lines');
    }

    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
});

it('refuses a record only a saved report remembers', function () {
    $account = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();
    $fresh = $account->replicate(['balance_cents'])->fill(['code' => '4999', 'name' => 'Old income', 'is_active' => false, 'is_system' => false]);
    $fresh->save();
    MemorizedReport::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'settings' => ['accountId' => $fresh->id]]);

    expect(fn () => $this->action->handle($fresh, $this->owner))->toThrow(DeletionBlockedException::class);
});

it('refuses the records the app relies on', function () {
    $system = Account::query()->where('is_system', true)->firstOrFail();
    $system->forceFill(['is_active' => false])->saveQuietly();
    $fund = Fund::create(['name' => 'General', 'fund_type' => FundType::cases()[0], 'is_default' => true, 'is_active' => false]);

    expect(fn () => $this->action->handle($system->fresh(), $this->owner))->toThrow(DeletionBlockedException::class)
        ->and(fn () => $this->action->handle($fund, $this->owner))->toThrow(DeletionBlockedException::class);
});

it('takes a bundle\'s own components with it, but not an item another bundle uses', function () {
    $bundle = Item::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);
    $part = Item::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);
    DB::table('item_components')->insert(['item_id' => $bundle->id, 'component_item_id' => $part->id, 'quantity' => 2, 'line_order' => 0]);

    expect(fn () => $this->action->handle($part, $this->owner))->toThrow(DeletionBlockedException::class);

    $this->action->handle($bundle, $this->owner);

    expect(DB::table('item_components')->count())->toBe(0)
        ->and(Item::query()->whereKey($bundle->id)->exists())->toBeFalse();

    $this->action->handle($part->fresh(), $this->owner);

    expect(Item::query()->whereKey($part->id)->exists())->toBeFalse();
});

it('deletes an employee never paid along with their payroll setup', function () {
    $profile = EmployeePayrollProfile::factory()->create(['company_id' => $this->company->id]);
    $employee = $profile->contact;
    $employee->forceFill(['is_active' => false])->save();

    $this->action->handle($employee->fresh(), $this->owner);

    expect(Contact::withTrashed()->whereKey($employee->id)->exists())->toBeFalse()
        ->and(EmployeePayrollProfile::query()->whereKey($profile->id)->exists())->toBeFalse();
});

it('deletes from the customer and items lists, and explains a refusal', function () {
    $gone = Contact::create(['display_name' => 'Gone Ltd', 'is_customer' => true, 'is_active' => false]);
    $active = Contact::create(['display_name' => 'Active Ltd', 'is_customer' => true, 'is_active' => true]);

    Livewire::actingAs($this->owner)
        ->test('pages::customers.index', ['company' => $this->company])
        ->set('showInactive', true)
        ->call('deleteListRecord', $gone->id)
        ->call('deleteListRecord', $active->id);

    expect(Contact::withTrashed()->whereKey($gone->id)->exists())->toBeFalse()
        ->and(Contact::query()->whereKey($active->id)->exists())->toBeTrue();

    $item = Item::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.lists.items', ['company' => $this->company])
        ->call('deleteListRecord', $item->id);

    expect(Item::query()->whereKey($item->id)->exists())->toBeFalse();
});

it('deletes an unused item through the API without asking for it to be inactive', function () {
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $item = Item::factory()->create(['company_id' => $this->company->id, 'is_active' => true]);
    app()->forgetInstance('current_company');

    $this->deleteJson("/api/v1/items/{$item->id}", [], ['Authorization' => "Bearer {$plain}"])->assertNoContent();

    app()->instance('current_company', $this->company);

    expect(Item::query()->whereKey($item->id)->exists())->toBeFalse()
        ->and(AccountingAuditLog::query()->where('action', AuditAction::ItemDeleted->value)->exists())->toBeTrue();
});
