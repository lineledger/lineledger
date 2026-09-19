<?php

use App\Models\Invoice;
use App\Services\Restore\IdMapper;
use App\Services\Restore\RowTransformer;

/**
 * Build a fresh transformer with the given user remap, optionally pre-loading
 * the IdMapper with some old=>new mappings.
 *
 * @param  array<int, int>  $userIdMap
 * @param  array<string, array<int, int>>  $idMap
 */
function buildTransformer(array $userIdMap = [], array $idMap = [], int $importingUserId = 999, int $newCompanyId = 7): RowTransformer
{
    $mapper = new IdMapper;

    foreach ($idMap as $table => $entries) {
        foreach ($entries as $old => $new) {
            $mapper->set($table, (int) $old, (int) $new);
        }
    }

    return new RowTransformer($mapper, $userIdMap, $importingUserId, $newCompanyId);
}

it('captures old id and drops the id column', function () {
    $transformer = buildTransformer();

    $result = $transformer->transform('invoices', ['id' => 42, 'invoice_no' => 'INV-1']);

    expect($result['old_id'])->toBe(42)
        ->and($result['row'])->not->toHaveKey('id')
        ->and($result['row']['invoice_no'])->toBe('INV-1')
        ->and($result['skip'])->toBeFalse();
});

it('returns null old_id when row has no id', function () {
    $transformer = buildTransformer();

    $result = $transformer->transform('invoice_settings', ['company_id' => 5, 'show_logo' => true]);

    expect($result['old_id'])->toBeNull();
});

it('swaps company_id to the new company id', function () {
    $transformer = buildTransformer(newCompanyId: 7);

    $result = $transformer->transform('invoices', ['id' => 1, 'company_id' => 99, 'invoice_no' => 'INV-1']);

    expect($result['row']['company_id'])->toBe(7);
});

it('leaves rows without company_id alone (child tables like journal_lines)', function () {
    $transformer = buildTransformer(newCompanyId: 7);

    $result = $transformer->transform('journal_lines', [
        'id' => 1,
        'journal_entry_id' => 10,
        'account_id' => 20,
    ]);

    expect($result['row'])->not->toHaveKey('company_id');
});

it('remaps user ids via the user map for *_user_id suffix columns', function () {
    $transformer = buildTransformer(userIdMap: [42 => 4200, 17 => 1700], importingUserId: 999);

    $result = $transformer->transform('invoices', [
        'id' => 1,
        'company_id' => 5,
        'posted_by_user_id' => 42,
        'voided_by_user_id' => 17,
    ]);

    expect($result['row']['posted_by_user_id'])->toBe(4200)
        ->and($result['row']['voided_by_user_id'])->toBe(1700);
});

it('falls back to importing user id when *_user_id value is missing from the map', function () {
    $transformer = buildTransformer(userIdMap: [42 => 4200], importingUserId: 999);

    $result = $transformer->transform('invoices', [
        'id' => 1,
        'company_id' => 5,
        'posted_by_user_id' => 999999, // not in the map
    ]);

    expect($result['row']['posted_by_user_id'])->toBe(999);
});

it('remaps explicit user-id columns (created_by, uploaded_by_id, invited_by, user_id)', function () {
    $transformer = buildTransformer(userIdMap: [1 => 100, 2 => 200, 3 => 300, 4 => 400], importingUserId: 999);

    $result = $transformer->transform('attachments', [
        'id' => 1,
        'company_id' => 5,
        'uploaded_by_id' => 1,
    ]);
    expect($result['row']['uploaded_by_id'])->toBe(100);

    $result = $transformer->transform('company_invitations', [
        'id' => 1,
        'company_id' => 5,
        'invited_by' => 2,
    ]);
    expect($result['row']['invited_by'])->toBe(200);

    $result = $transformer->transform('company_api_keys', [
        'id' => 1,
        'company_id' => 5,
        'created_by_user_id' => 3, // also matches the *_user_id suffix rule
    ]);
    expect($result['row']['created_by_user_id'])->toBe(300);

    $result = $transformer->transform('memorized_reports', [
        'id' => 1,
        'company_id' => 5,
        'user_id' => 4,
    ]);
    expect($result['row']['user_id'])->toBe(400);
});

it('leaves null user-id fields null', function () {
    $transformer = buildTransformer(userIdMap: [42 => 4200], importingUserId: 999);

    $result = $transformer->transform('invoices', [
        'id' => 1,
        'company_id' => 5,
        'posted_by_user_id' => null,
        'voided_by_user_id' => null,
    ]);

    expect($result['row']['posted_by_user_id'])->toBeNull()
        ->and($result['row']['voided_by_user_id'])->toBeNull();
});

it('remaps parent foreign keys via the IdMapper for child tables', function () {
    $transformer = buildTransformer(idMap: [
        'invoices' => [7 => 42],
        'items' => [3 => 30],
    ]);

    $result = $transformer->transform('invoice_lines', [
        'id' => 1,
        'invoice_id' => 7,
        'item_id' => 3,
        'account_id' => 50,
    ]);

    expect($result['row']['invoice_id'])->toBe(42)
        ->and($result['row']['item_id'])->toBe(30)
        // account_id was not mapped — left untouched.
        ->and($result['row']['account_id'])->toBe(50);
});

it('leaves parent foreign keys untouched when the IdMapper has no entry', function () {
    $transformer = buildTransformer();

    $result = $transformer->transform('invoice_lines', [
        'id' => 1,
        'invoice_id' => 7,
    ]);

    // No mapping recorded, no crash, value passes through.
    expect($result['row']['invoice_id'])->toBe(7);
});

it('translates polymorphic source_type/source_id on journal_entries', function () {
    $transformer = buildTransformer(idMap: [
        'invoices' => [5 => 50],
    ]);

    $result = $transformer->transform('journal_entries', [
        'id' => 1,
        'company_id' => 5,
        'entry_no' => 'JE-1',
        'source_type' => Invoice::class,
        'source_id' => 5,
    ]);

    expect($result['row']['source_type'])->toBe(Invoice::class)
        ->and($result['row']['source_id'])->toBe(50);
});

it('leaves polymorphic fields unchanged when the type is unknown', function () {
    $transformer = buildTransformer(idMap: ['invoices' => [5 => 50]]);

    $result = $transformer->transform('journal_entries', [
        'id' => 1,
        'company_id' => 5,
        'source_type' => 'App\\Models\\RenamedNoLongerExists',
        'source_id' => 5,
    ]);

    expect($result['row']['source_type'])->toBe('App\\Models\\RenamedNoLongerExists')
        ->and($result['row']['source_id'])->toBe(5);
});

it('leaves polymorphic fields alone when the id is not mapped yet', function () {
    $transformer = buildTransformer(); // empty IdMapper

    $result = $transformer->transform('journal_entries', [
        'id' => 1,
        'company_id' => 5,
        'source_type' => Invoice::class,
        'source_id' => 999,
    ]);

    expect($result['row']['source_id'])->toBe(999);
});

it('applies companies-row quirks: drops slug, logo_path, stripe_* and captures old id', function () {
    $transformer = buildTransformer();

    $result = $transformer->transform('companies', [
        'id' => 13,
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
        'logo_path' => 'company-logos/abc.png',
        'stripe_account_id' => 'acct_xyz',
        'stripe_connected_at' => '2026-01-01T00:00:00Z',
        'currency_code' => 'CAD',
    ]);

    expect($result['old_id'])->toBe(13)
        ->and($result['row'])->not->toHaveKey('id')
        ->and($result['row'])->not->toHaveKey('slug')
        ->and($result['row'])->not->toHaveKey('stripe_account_id')
        ->and($result['row'])->not->toHaveKey('stripe_connected_at')
        ->and($result['row']['logo_path'])->toBeNull()
        ->and($result['row']['name'])->toBe('Acme Inc.')
        ->and($result['row']['currency_code'])->toBe('CAD');
});

it('remaps companies-row account FKs via IdMapper', function () {
    $transformer = buildTransformer(idMap: [
        'accounts' => [1 => 100, 2 => 200, 3 => 300, 4 => 400],
    ]);

    $result = $transformer->transform('companies', [
        'id' => 13,
        'default_inventory_asset_account_id' => 1,
        'default_cogs_account_id' => 2,
        'exchange_gain_loss_account_id' => 3,
        'unrealized_gain_loss_account_id' => 4,
    ]);

    expect($result['row']['default_inventory_asset_account_id'])->toBe(100)
        ->and($result['row']['default_cogs_account_id'])->toBe(200)
        ->and($result['row']['exchange_gain_loss_account_id'])->toBe(300)
        ->and($result['row']['unrealized_gain_loss_account_id'])->toBe(400);
});

it('preserves the bundle-relative path on attachments and remaps the polymorphic pair', function () {
    $transformer = buildTransformer(idMap: [
        'invoices' => [11 => 110],
    ]);

    $result = $transformer->transform('attachments', [
        'id' => 1,
        'company_id' => 5,
        'attachable_type' => Invoice::class,
        'attachable_id' => 11,
        'disk' => 'local',
        'path' => 'files/attachments/Invoice/11/receipt.pdf',
    ]);

    expect($result['row']['path'])->toBe('files/attachments/Invoice/11/receipt.pdf')
        ->and($result['row']['attachable_id'])->toBe(110)
        ->and($result['row']['attachable_type'])->toBe(Invoice::class);
});

it('nulls token columns on company_api_keys rows', function () {
    $transformer = buildTransformer();

    $result = $transformer->transform('company_api_keys', [
        'id' => 1,
        'company_id' => 5,
        'label' => 'CI key',
        'token_hash' => 'hash-from-source-instance',
        'last_four' => '1234',
    ]);

    expect($result['row']['token_hash'])->toBeNull()
        ->and($result['row']['last_four'])->toBe('1234');
});

it('passes through company_members rows whose user_id was matched by email', function () {
    // user 42 matched by email to target user 4200 — different from the importer (999).
    $transformer = buildTransformer(userIdMap: [42 => 4200], importingUserId: 999);

    $result = $transformer->transform('company_members', [
        'id' => 1,
        'company_id' => 5,
        'user_id' => 42,
        'role' => 'admin',
    ]);

    expect($result['skip'])->toBeFalse()
        ->and($result['row']['user_id'])->toBe(4200)
        ->and($result['row']['role'])->toBe('admin');
});

it('skips company_members rows whose user_id falls back to the importing user', function () {
    // user 42 has no email match → falls back to importer (999).
    $transformer = buildTransformer(userIdMap: [], importingUserId: 999);

    $result = $transformer->transform('company_members', [
        'id' => 1,
        'company_id' => 5,
        'user_id' => 42,
        'role' => 'admin',
    ]);

    expect($result['skip'])->toBeTrue();
});

it('leaves JSON-column array values as PHP arrays in the output (orchestrator encodes)', function () {
    $transformer = buildTransformer();

    $result = $transformer->transform('stock_movements', [
        'id' => 1,
        'company_id' => 5,
        'item_id' => 7,
        'consumed_layers' => [
            ['layer_id' => 1, 'qty' => '2.0000'],
            ['layer_id' => 2, 'qty' => '1.0000'],
        ],
    ]);

    expect($result['row']['consumed_layers'])->toBeArray()
        ->and($result['row']['consumed_layers'])->toHaveCount(2)
        ->and($result['row']['consumed_layers'][0]['layer_id'])->toBe(1);
});

it('remaps opening_balance_states onto the restored journal entry and user', function () {
    $transformer = buildTransformer(
        userIdMap: [3 => 300],
        idMap: ['journal_entries' => [55 => 5500]],
        newCompanyId: 7,
    );

    $result = $transformer->transform('opening_balance_states', [
        'id' => 9,
        'company_id' => 1,
        'as_of_date' => '2025-12-31',
        'status' => 'active',
        'journal_entry_id' => 55,
        'applied_at' => null,
        'apply_error' => null,
        'created_by_user_id' => 3,
    ]);

    expect($result['old_id'])->toBe(9)
        ->and($result['row']['company_id'])->toBe(7)
        ->and($result['row']['journal_entry_id'])->toBe(5500)
        ->and($result['row']['created_by_user_id'])->toBe(300)
        ->and($result['row']['as_of_date'])->toBe('2025-12-31')
        ->and($result['row']['status'])->toBe('active')
        ->and($result['deferred'])->toBe([]);
});

it('remaps opening_balance_rows onto the restored state and account', function () {
    $transformer = buildTransformer(
        userIdMap: [3 => 300],
        idMap: [
            'opening_balance_states' => [9 => 90],
            'accounts' => [12 => 1200],
        ],
        newCompanyId: 7,
    );

    $result = $transformer->transform('opening_balance_rows', [
        'id' => 1,
        'company_id' => 1,
        'opening_balance_state_id' => 9,
        'account_id' => 12,
        'debit_cents' => 250_000,
        'credit_cents' => 0,
        'updated_by_user_id' => 3,
    ]);

    expect($result['old_id'])->toBe(1)
        ->and($result['row']['company_id'])->toBe(7)
        ->and($result['row']['opening_balance_state_id'])->toBe(90)
        ->and($result['row']['account_id'])->toBe(1200)
        ->and($result['row']['updated_by_user_id'])->toBe(300)
        ->and($result['row']['debit_cents'])->toBe(250_000)
        ->and($result['row']['credit_cents'])->toBe(0);
});

it('remaps the sub-ledger contact on cheque_lines alongside its account and cheque', function () {
    $transformer = buildTransformer(idMap: [
        'cheques' => [4 => 40],
        'accounts' => [12 => 1200],
        'contacts' => [8 => 80],
        'tax_codes' => [2 => 20],
    ]);

    $result = $transformer->transform('cheque_lines', [
        'id' => 1,
        'cheque_id' => 4,
        'account_id' => 12,
        'contact_id' => 8,
        'tax_code_id' => 2,
        'amount_cents' => 12_500,
    ]);

    expect($result['row']['cheque_id'])->toBe(40)
        ->and($result['row']['account_id'])->toBe(1200)
        ->and($result['row']['contact_id'])->toBe(80)
        ->and($result['row']['tax_code_id'])->toBe(20)
        ->and($result['row']['amount_cents'])->toBe(12_500);
});

it('carries the cheque payee address snapshot through untouched while remapping its FKs', function () {
    $transformer = buildTransformer(idMap: [
        'accounts' => [12 => 1200],
        'contacts' => [8 => 80],
        'credit_memos' => [6 => 60],
        'journal_entries' => [55 => 5500],
    ]);

    $result = $transformer->transform('cheques', [
        'id' => 3,
        'company_id' => 1,
        'bank_account_id' => 12,
        'payee_contact_id' => 8,
        'credit_memo_id' => 6,
        'journal_entry_id' => 55,
        'payee_name' => 'Acme Supplies',
        'payee_line1' => '100 Main St',
        'payee_line2' => 'Unit 4',
        'payee_city' => 'Kelowna',
        'payee_region' => 'BC',
        'payee_postal_code' => 'V1Y 1A1',
        'payee_country' => 'CA',
        'is_opening_balance' => true,
    ]);

    expect($result['row']['bank_account_id'])->toBe(1200)
        ->and($result['row']['payee_contact_id'])->toBe(80)
        ->and($result['row']['credit_memo_id'])->toBe(60)
        ->and($result['row']['journal_entry_id'])->toBe(5500)
        ->and($result['row']['payee_line1'])->toBe('100 Main St')
        ->and($result['row']['payee_line2'])->toBe('Unit 4')
        ->and($result['row']['payee_city'])->toBe('Kelowna')
        ->and($result['row']['payee_region'])->toBe('BC')
        ->and($result['row']['payee_postal_code'])->toBe('V1Y 1A1')
        ->and($result['row']['payee_country'])->toBe('CA')
        ->and($result['row']['is_opening_balance'])->toBeTrue();
});

it('remaps the fund dimension on employee_payroll_profiles', function () {
    $transformer = buildTransformer(idMap: [
        'contacts' => [8 => 80],
        'funds' => [5 => 50],
    ]);

    $result = $transformer->transform('employee_payroll_profiles', [
        'id' => 1,
        'company_id' => 1,
        'contact_id' => 8,
        'fund_id' => 5,
    ]);

    expect($result['row']['contact_id'])->toBe(80)
        ->and($result['row']['fund_id'])->toBe(50);
});

it('translates the polymorphic source pair snapshotted on tax_return_lines', function () {
    $transformer = buildTransformer(idMap: [
        'invoices' => [5 => 50],
        'tax_returns' => [2 => 20],
    ]);

    $result = $transformer->transform('tax_return_lines', [
        'id' => 1,
        'tax_return_id' => 2,
        'source_type' => Invoice::class,
        'source_id' => 5,
    ]);

    expect($result['row']['tax_return_id'])->toBe(20)
        ->and($result['row']['source_type'])->toBe(Invoice::class)
        ->and($result['row']['source_id'])->toBe(50);
});
