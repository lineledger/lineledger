<?php

use App\Concerns\GuardsPostedDeletion;
use App\Enums\AccountSubtype;
use App\Enums\DeletableDocumentType;
use App\Exceptions\Posting\PostedDocumentDeletionException;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\SalesReceipt;
use App\Models\StockAdjustment;
use App\Models\TaxReturnPayment;
use App\Models\Transfer;
use App\Models\VendorCredit;
use App\Services\Posting\JournalPoster;

/*
 * Outside TransactionDeleter, nothing may delete a posted document or a
 * posted journal entry: it would leave the ledger out of step with its
 * documents. The models refuse, whatever path the delete comes from.
 */

it('guards every posting document model', function (string $class) {
    expect(class_uses_recursive($class))->toHaveKey(GuardsPostedDeletion::class);
})->with([
    Invoice::class,
    Bill::class,
    BillPayment::class,
    CustomerReceipt::class,
    SalesReceipt::class,
    Cheque::class,
    Deposit::class,
    Transfer::class,
    Expense::class,
    CreditMemo::class,
    VendorCredit::class,
    StockAdjustment::class,
    TaxReturnPayment::class,
]);

it('guards every deletable type that owns a journal entry', function () {
    foreach (DeletableDocumentType::cases() as $type) {
        if (! $type->postsToLedger() || $type === DeletableDocumentType::JournalEntry) {
            continue; // estimates and orders never post; a journal entry guards itself (booted())
        }

        expect(class_uses_recursive($type->modelClass()))->toHaveKey(GuardsPostedDeletion::class);
    }
});

it('refuses to delete a posted journal entry through the model, and lets a draft go', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->firstOrFail();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();

    $make = function () use ($expense, $bank): JournalEntry {
        $entry = JournalEntry::create(['entry_no' => 'JE-'.uniqid(), 'entry_date' => now()->toDateString(), 'memo' => 'Guard']);
        $entry->lines()->create(['account_id' => $expense->id, 'debit_cents' => 100, 'credit_cents' => 0, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 100, 'line_order' => 1]);

        return $entry->fresh('lines');
    };

    $posted = app(JournalPoster::class)->post($make());

    expect(fn () => $posted->delete())->toThrow(PostedDocumentDeletionException::class);

    $draft = $make();
    $draft->lines()->delete();
    $draft->delete();

    expect(JournalEntry::query()->whereKey($draft->id)->exists())->toBeFalse();
});
