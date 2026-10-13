<?php

use App\Enums\CompanyRole;
use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * The line-item table's resizable-columns wiring, as rendered: the directive on
 * the <table> with its storage key, Description as the flexible column, and a
 * compiled drag handle in every resizable header. wire:ignore.self on the table
 * and on each resizable <th> is what keeps the inline widths across Livewire
 * morphs (see resources/js/resizable-columns.js).
 */
function assertResizableLineTable(string $html, string $storageKey): void
{
    // Every Flux / Blade component compiled (no leaked literal tags).
    expect($html)
        ->not->toContain('<flux:')
        ->not->toContain('<x-col-resize-handle');

    expect($html)->toContain('x-resizable-columns="\''.$storageKey.'\'"');

    $table = Str::of($html)->after('x-resizable-columns="\''.$storageKey.'\'"')->before('</thead>')->toString();
    $tableTag = Str::of($html)->after('x-resizable-columns="\''.$storageKey.'\'"')->before('>')->toString();

    expect($tableTag)->toContain('wire:ignore.self');
    expect($table)
        ->toContain('data-col-flex')
        ->toContain('data-col="account"')
        ->toContain('data-col-resize-handle=');

    $columns = preg_match_all('/<th\b[^>]*\bdata-col="[a-z-]+"[^>]*>/', $table, $headers);
    $handles = substr_count($table, 'data-col-resize-handle=');

    expect($columns)->toBeGreaterThan(1)
        ->and($handles)->toBe($columns);

    foreach ($headers[0] as $th) {
        expect($th)
            ->toContain('wire:ignore.self')
            ->toContain('relative');
    }

    // A resizable column's handle carries its edge and stays out of the a11y tree.
    expect($table)
        ->toMatch('/data-col-resize-handle="(start|end)"/')
        ->toContain('aria-hidden="true"');
}

it('renders resizable line columns on every line-item form', function (string $route, string $storageKey) {
    $html = $this->get(route($route, ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    assertResizableLineTable($html, $storageKey);
})->with([
    'cheques' => ['cheques.create', 'cheque-lines'],
    'expenses' => ['expenses.create', 'expense-lines'],
    'bills' => ['bills.create', 'bill-lines'],
    'reimbursements' => ['reimbursements.create', 'reimbursement-lines'],
    'invoices' => ['invoices.create', 'invoice-lines'],
    'credit memos' => ['credit-memos.create', 'credit-memo-lines'],
    'vendor credits' => ['vendor-credits.create', 'vendor-credit-lines'],
    'sales receipts' => ['sales-receipts.create', 'sales-receipt-lines'],
    'purchase orders' => ['purchase-orders.create', 'purchase-order-lines'],
    'estimates' => ['estimates.create', 'estimate-lines'],
    'sales orders' => ['sales-orders.create', 'sales-order-lines'],
    'recurring documents' => ['recurring.create', 'recurring-lines'],
    'invoice templates' => ['invoice-templates.create', 'invoice-template-lines'],
]);

it('puts the handle on the edge facing the flexible Description column', function () {
    $html = $this->get(route('cheques.create', ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    $head = Str::of($html)->after("x-resizable-columns=\"'cheque-lines'\"")->before('</thead>')->toString();

    // Account sits left of Description, so its handle is on its right edge;
    // Amount sits right of it, so its handle is on its left edge.
    expect($head)
        ->toMatch('/data-col="account"[^>]*>.*?data-col-resize-handle="end"/s')
        ->toMatch('/data-col="amount"[^>]*>\s*<span\s+data-col-resize-handle="start"/s');
});

it('renders resizable columns on the deposit "Other deposits" table once it has a line', function () {
    $html = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->call('addOtherLine')
        ->html();

    assertResizableLineTable($html, 'deposit-other-lines');
});

it('renders resizable columns on the inbox review line grid', function () {
    Storage::fake('local');

    $item = InboxItem::create([
        'source' => InboxItemSource::Upload,
        'status' => InboxItemStatus::NeedsReview,
        'original_filename' => 'receipt.jpg',
        'mime' => 'image/jpeg',
        'created_by_user_id' => $this->user->id,
    ]);
    $path = 'attachments/'.$this->company->id.'/inbox_items/'.$item->id.'/receipt.jpg';
    Storage::disk('local')->put($path, 'fake-bytes');
    $attachment = Attachment::create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'receipt.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'uploaded_by_id' => $this->user->id,
    ]);
    $item->forceFill([
        'attachment_id' => $attachment->id,
        'suggested_document_type' => 'bill',
        'extracted' => ['vendor' => 'Some Vendor', 'amount_cents' => 6000, 'currency' => 'CAD', 'date' => '2026-06-20'],
    ])->save();

    $html = Livewire::test('pages::inbox.show', ['company' => $this->company, 'item' => $item->fresh()])->html();

    assertResizableLineTable($html, 'inbox-lines');
});

it('keeps the drag handle desktop-only and pointer-only', function () {
    $html = Blade::render('<x-col-resize-handle /><x-col-resize-handle edge="start" />');

    expect($html)
        ->toContain('data-col-resize-handle="end"')
        ->toContain('data-col-resize-handle="start"')
        ->toContain('hidden')
        ->toContain('lg:block')
        ->toContain('cursor-col-resize')
        ->toContain('aria-hidden="true"')
        ->toContain('-right-1.5')
        ->toContain('-left-1.5');
});

it('gives every resizable line table its own storage key', function () {
    $keys = collect(File::allFiles(resource_path('views/pages')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
        ->flatMap(function ($file) {
            preg_match_all("/x-resizable-columns=\"'([^']+)'\"/", $file->getContents(), $m);

            return $m[1];
        });

    expect($keys)->toHaveCount(15)
        ->and($keys->duplicates())->toBeEmpty();
});
