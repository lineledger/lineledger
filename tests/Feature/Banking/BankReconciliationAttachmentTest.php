<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bankAccount = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->orderBy('code')
        ->firstOrFail();

    $this->reconciliation = BankReconciliation::factory()->create([
        'account_id' => $this->bankAccount->id,
        'ending_balance_cents' => 346867,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('uploads a statement to an in-progress reconciliation', function () {
    $file = UploadedFile::fake()->create('bmo-statement.pdf', 200, 'application/pdf');

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bankAccount->id)
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = Attachment::firstOrFail();

    expect($attachment->attachable_id)->toBe($this->reconciliation->id)
        ->and($attachment->attachable_type)->toBe($this->reconciliation->getMorphClass())
        ->and($attachment->company_id)->toBe($this->company->id)
        ->and($attachment->original_filename)->toBe('bmo-statement.pdf');

    Storage::disk('local')->assertExists($attachment->path);
});

it('attaches pending files automatically when reconciling', function () {
    // A balanced reconciliation (ending balance matches the zero cleared balance).
    $balanced = $this->reconciliation;
    $balanced->update(['beginning_balance_cents' => 0, 'ending_balance_cents' => 0]);

    $file = UploadedFile::fake()->create('bmo-statement.pdf', 200, 'application/pdf');

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bankAccount->id)
        ->set('newAttachments', [$file])
        ->call('reconcileNow')
        ->assertHasNoErrors()
        ->assertSet('newAttachments', []);

    $attachment = Attachment::firstOrFail();

    expect($attachment->attachable_id)->toBe($balanced->id)
        ->and($attachment->attachable_type)->toBe($balanced->getMorphClass())
        ->and($attachment->original_filename)->toBe('bmo-statement.pdf');

    Storage::disk('local')->assertExists($attachment->path);
    expect($balanced->fresh()->isCompleted())->toBeTrue();
});

it('rejects an oversized reconciliation attachment', function () {
    $file = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bankAccount->id)
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasErrors(['newAttachments.0']);

    expect(Attachment::count())->toBe(0);
});

it('uploads and removes a statement on the reconciliation detail page', function () {
    $completed = BankReconciliation::factory()->completed()->create([
        'account_id' => $this->bankAccount->id,
    ]);

    $file = UploadedFile::fake()->create('statement.pdf', 120, 'application/pdf');

    Livewire::test('pages::banking.reconciliation-show', ['company' => $this->company, 'reconciliation' => $completed])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = Attachment::firstOrFail();
    $path = $attachment->path;
    expect($attachment->attachable_id)->toBe($completed->id);

    Livewire::test('pages::banking.reconciliation-show', ['company' => $this->company, 'reconciliation' => $completed])
        ->call('removeAttachment', $attachment->id);

    expect(Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('purges attachment blobs when the reconciliation is deleted', function () {
    Storage::disk('local')->put('attachments/'.$this->company->id.'/stmt.pdf', 'PDF-BYTES');
    $attachment = Attachment::create([
        'attachable_type' => $this->reconciliation->getMorphClass(),
        'attachable_id' => $this->reconciliation->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/stmt.pdf',
        'original_filename' => 'stmt.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 9,
        'uploaded_by_id' => $this->user->id,
    ]);

    $this->reconciliation->delete();

    expect(Attachment::whereKey($attachment->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing('attachments/'.$this->company->id.'/stmt.pdf');
});
