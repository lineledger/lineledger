<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\ExpenseStatus;
use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Jobs\Inbox\ProcessInboxItem;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Inbox\OCR\ClaudeReceiptIntelligence;
use App\Services\Inbox\OCR\Contracts\ReceiptIntelligence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expenseA = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $this->expenseB = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->skip(1)->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function stageReceiptItem(Company $company, User $user): InboxItem
{
    $item = InboxItem::create([
        'source' => InboxItemSource::Upload,
        'status' => InboxItemStatus::Pending,
        'original_filename' => 'receipt.jpg',
        'mime' => 'image/jpeg',
        'created_by_user_id' => $user->id,
    ]);

    $path = 'attachments/'.$company->id.'/inbox_items/'.$item->id.'/'.Str::ulid().'.jpg';
    Storage::disk('local')->put($path, 'fake-bytes');

    $attachment = Attachment::create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'receipt.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'uploaded_by_id' => $user->id,
    ]);

    $item->forceFill(['attachment_id' => $attachment->id])->save();

    return $item->refresh();
}

function postedExpenseForVendor(Account $payment, int $contactId, Account $category, string $date): void
{
    $expense = Expense::create([
        'payment_account_id' => $payment->id,
        'expense_date' => $date,
        'payee_contact_id' => $contactId,
        'payee_name' => 'history',
        'amount_cents' => 10000,
        'status' => ExpenseStatus::Posted->value,
        'posted_at' => now(),
    ]);

    $expense->lines()->create(['account_id' => $category->id, 'amount_cents' => 10000, 'line_order' => 0]);
}

function enableInboxAi(Company $company): void
{
    config()->set('inbox.ai.enabled', true);
    config()->set('inbox.ai.driver', 'http');
    config()->set('services.anthropic.key', 'test-key');
    $company->setInboxState(['ocr_enabled' => true]);

    app()->bind(ReceiptIntelligence::class, fn () => new ClaudeReceiptIntelligence(
        apiKey: 'test-key',
        baseUrl: 'https://api.anthropic.com',
        model: 'claude-sonnet-4-6',
    ));
}

/**
 * Fake the Anthropic endpoint for both the OCR (provide_receipt) and the
 * classification (classify_transactions) tools, switching on the requested tool.
 *
 * @param  array<string, mixed>  $receipt  the provide_receipt tool input
 */
function fakeAnthropic(array $receipt, ?string $classifyCode = null): void
{
    Http::fake(['*/v1/messages' => function ($request) use ($receipt, $classifyCode) {
        $tool = $request->data()['tools'][0]['name'] ?? '';

        if ($tool === 'classify_transactions') {
            return Http::response(['content' => [[
                'type' => 'tool_use',
                'name' => 'classify_transactions',
                'input' => ['classifications' => $classifyCode !== null
                    ? [['index' => 0, 'account_code' => $classifyCode]]
                    : []],
            ]]], 200);
        }

        return Http::response(['content' => [[
            'type' => 'tool_use',
            'name' => 'provide_receipt',
            'input' => $receipt,
        ]]], 200);
    }]);
}

function assertClassifyNotSent(): void
{
    Http::assertNotSent(fn ($request): bool => ($request->data()['tools'][0]['name'] ?? '') === 'classify_transactions');
}

function assertClassifySent(): void
{
    Http::assertSent(fn ($request): bool => ($request->data()['tools'][0]['name'] ?? '') === 'classify_transactions');
}

it('stores a contact-default category from the job and leaves OCR tax untouched', function () {
    enableInboxAi($this->company);

    $vendor = Contact::factory()->vendor()->create([
        'display_name' => 'Staples Canada',
        'default_expense_account_id' => $this->expenseB->id,
    ]);

    fakeAnthropic([
        'vendor' => 'Staples Canada',
        'subtotal' => 100.00,
        'total' => 105.00,
        'currency' => 'CAD',
        'date' => '2026-06-20',
        'taxes' => [['label' => 'GST', 'rate' => 5, 'amount' => 5.00]],
        'line_items' => [],
    ]);

    $item = stageReceiptItem($this->company, $this->user);
    (new ProcessInboxItem($item->id))->handle(app(ReceiptIntelligence::class));

    $item->refresh();

    expect($item->suggested_contact_id)->toBe($vendor->id)
        ->and($item->extracted['suggested_account_id'])->toBe($this->expenseB->id)
        ->and($item->extracted['taxes'][0]['tax_code_id'])->not->toBeNull();

    assertClassifyNotSent();
});

it('uses contact history before consulting AI', function () {
    enableInboxAi($this->company);

    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Tools']);
    postedExpenseForVendor($this->bank, $vendor->id, $this->expenseA, now()->subDays(10)->toDateString());

    fakeAnthropic([
        'vendor' => 'Acme Tools',
        'total' => 60.00,
        'currency' => 'CAD',
        'date' => '2026-06-20',
        'line_items' => [],
    ], classifyCode: $this->expenseB->code);

    $item = stageReceiptItem($this->company, $this->user);
    (new ProcessInboxItem($item->id))->handle(app(ReceiptIntelligence::class));

    $item->refresh();

    expect($item->extracted['suggested_account_id'])->toBe($this->expenseA->id); // history, not AI

    assertClassifyNotSent();
});

it('falls back to AI for a vendor with no contact or history', function () {
    enableInboxAi($this->company);

    fakeAnthropic([
        'vendor' => 'Unknown Co',
        'total' => 60.00,
        'currency' => 'CAD',
        'date' => '2026-06-20',
        'line_items' => [],
    ], classifyCode: $this->expenseB->code);

    $item = stageReceiptItem($this->company, $this->user);
    (new ProcessInboxItem($item->id))->handle(app(ReceiptIntelligence::class));

    $item->refresh();

    expect($item->suggested_contact_id)->toBeNull()
        ->and($item->extracted['suggested_account_id'])->toBe($this->expenseB->id)
        ->and($item->extracted['suggested_account_reason'])->toContain('AI');

    assertClassifySent();
});

it('pre-selects a deterministic history category on the review page without AI', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Recurring Vendor']);
    postedExpenseForVendor($this->bank, $vendor->id, $this->expenseA, now()->subDays(7)->toDateString());

    $item = stageReceiptItem($this->company, $this->user);
    $item->forceFill([
        'status' => InboxItemStatus::NeedsReview->value,
        'suggested_contact_id' => $vendor->id,
        'suggested_document_type' => 'bill',
        'extracted' => ['vendor' => 'Recurring Vendor', 'amount_cents' => 6000, 'currency' => 'CAD', 'date' => '2026-06-20'],
    ])->save();

    Livewire::test('pages::inbox.show', ['company' => $this->company, 'item' => $item])
        ->assertSet('lines.0.account_id', $this->expenseA->id);
});
