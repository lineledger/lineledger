<?php

use App\Enums\McpProposalStatus;
use App\Models\Invoice;
use App\Services\Restore\IdMapper;
use App\Services\Restore\RowTransformer;
use Carbon\CarbonImmutable;

/**
 * Build a fresh transformer with the given user remap, optionally pre-loading
 * the IdMapper with some old=>new mappings.
 *
 * @param  array<int, int>  $userIdMap
 * @param  array<string, array<int, int>>  $idMap
 */
function buildTransformer(array $userIdMap = [], array $idMap = [], int $importingUserId = 999, int $newCompanyId = 7, array $fallbackUserIds = []): RowTransformer
{
    $mapper = new IdMapper;

    foreach ($idMap as $table => $entries) {
        foreach ($entries as $old => $new) {
            $mapper->set($table, (int) $old, (int) $new);
        }
    }

    return new RowTransformer($mapper, $userIdMap, $importingUserId, $newCompanyId, $fallbackUserIds);
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
        // accounts restore before invoice lines, so account 50 isn't in the
        // bundle: an orphan, restored as null rather than the source id.
        ->and($result['row']['account_id'])->toBeNull()
        ->and($result['orphans'])->toBe(['account_id' => ['table' => 'accounts', 'id' => 50]]);
});

it('never copies a source id through for a parent restored earlier but missing from the bundle', function () {
    $transformer = buildTransformer();

    $result = $transformer->transform('invoice_lines', [
        'id' => 1,
        'invoice_id' => 7,
    ]);

    // On this instance id 7 names another organization's invoice, or none.
    expect($result['row']['invoice_id'])->toBeNull()
        ->and($result['orphans'])->toBe(['invoice_id' => ['table' => 'invoices', 'id' => 7]])
        ->and($result['deferred'])->toBe([]);
});

it('defers a self-reference to a row restored later', function () {
    // A voided entry points at its reversal, which has a higher id.
    $transformer = buildTransformer(idMap: ['journal_entries' => [1 => 10]]);

    $result = $transformer->transform('journal_entries', [
        'id' => 2,
        'company_id' => 5,
        'reverses_entry_id' => 1,
        'reversed_by_entry_id' => 3,
    ]);

    expect($result['row']['reverses_entry_id'])->toBe(10)
        ->and($result['row']['reversed_by_entry_id'])->toBeNull()
        ->and($result['deferred'])->toBe(['reversed_by_entry_id' => ['table' => 'journal_entries', 'id' => 3]])
        ->and($result['orphans'])->toBe([]);
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

it('treats a polymorphic id of an unknown type as an orphan', function () {
    $transformer = buildTransformer(idMap: ['invoices' => [5 => 50]]);

    $result = $transformer->transform('journal_entries', [
        'id' => 1,
        'company_id' => 5,
        'source_type' => 'App\\Models\\RenamedNoLongerExists',
        'source_id' => 5,
    ]);

    expect($result['row']['source_type'])->toBe('App\\Models\\RenamedNoLongerExists')
        ->and($result['row']['source_id'])->toBeNull()
        ->and($result['orphans'])->toBe(['source_id' => ['table' => 'App\\Models\\RenamedNoLongerExists', 'id' => 5]]);
});

it('defers a polymorphic id whose document restores later', function () {
    // Journal entries restore before the invoices they post.
    $transformer = buildTransformer(); // empty IdMapper

    $result = $transformer->transform('journal_entries', [
        'id' => 1,
        'company_id' => 5,
        'source_type' => Invoice::class,
        'source_id' => 999,
    ]);

    expect($result['row']['source_id'])->toBeNull()
        ->and($result['deferred'])->toBe(['source_id' => ['table' => 'invoices', 'id' => 999]]);
});

it('remaps the ids inside JSON columns once every table is in, dropping ids that do not map', function () {
    $transformer = buildTransformer(idMap: [
        'stock_layers' => [11 => 110, 12 => 120],
        'journal_lines' => [5 => 50],
        'accounts' => [3 => 30, 4 => 40],
    ]);

    expect($transformer->remapJsonIds('stock_movements', [
        'consumed_layers' => [['layer_id' => 11, 'qty' => '2'], ['layer_id' => 12, 'qty' => '1']],
    ]))->toBe(['consumed_layers' => [['layer_id' => 110, 'qty' => '2'], ['layer_id' => 120, 'qty' => '1']]]);

    expect($transformer->remapJsonIds('bank_reconciliations', ['marked_line_ids' => [5, 6]]))
        ->toBe(['marked_line_ids' => [50]]);

    // Reconciliation defaults are keyed by bank account id, and hold account ids.
    expect($transformer->remapJsonIds('companies', [
        'settings' => ['locale' => 'fr', 'reconciliation' => ['defaults' => [
            3 => ['service_charge_account_id' => 4, 'interest_account_id' => 9],
        ]]],
    ]))->toBe(['settings' => ['locale' => 'fr', 'reconciliation' => ['defaults' => [
        30 => ['service_charge_account_id' => 40, 'interest_account_id' => null],
    ]]]]);

    // A bare id, only on the preference that holds one.
    expect($transformer->remapJsonIds('user_preferences', ['pref_key' => 'banking.last_account', 'value' => 3]))
        ->toBe(['value' => 30])
        ->and($transformer->remapJsonIds('user_preferences', ['pref_key' => 'reports.columns', 'value' => 3]))
        ->toBe([]);
});

it('leaves out the personal preferences of a user who matched nobody here', function () {
    // Bundle user 42 matched; 17 fell back to the importer (999).
    $transformer = buildTransformer(userIdMap: [42 => 4200, 17 => 999], importingUserId: 999, fallbackUserIds: [17]);

    $matched = $transformer->transform('user_preferences', ['id' => 1, 'company_id' => 5, 'user_id' => 42, 'pref_key' => 'a', 'value' => 1]);
    $fallback = $transformer->transform('user_preferences', ['id' => 2, 'company_id' => 5, 'user_id' => 17, 'pref_key' => 'a', 'value' => 1]);
    $unknown = $transformer->transform('nav_preferences', ['id' => 3, 'company_id' => 5, 'user_id' => 555]);
    $notPersonal = $transformer->transform('memorized_reports', ['id' => 4, 'company_id' => 5, 'user_id' => 17]);

    expect($matched['skip'])->toBeFalse()
        ->and($matched['row']['user_id'])->toBe(4200)
        ->and($fallback['skip'])->toBeTrue()
        ->and($unknown['skip'])->toBeTrue()
        ->and($notPersonal['skip'])->toBeFalse()
        ->and($notPersonal['row']['user_id'])->toBe(999);
});

it('remaps the document an inbox item was promoted to', function () {
    $transformer = buildTransformer(idMap: ['bills' => [8 => 80], 'expenses' => [9 => 90]]);

    $bill = $transformer->transform('inbox_items', ['id' => 1, 'company_id' => 5, 'promoted_document_type' => 'reimbursement', 'promoted_document_id' => 8]);
    $expense = $transformer->transform('inbox_items', ['id' => 2, 'company_id' => 5, 'promoted_document_type' => 'expense', 'promoted_document_id' => 9]);

    expect($bill['row']['promoted_document_id'])->toBe(80)
        ->and($expense['row']['promoted_document_id'])->toBe(90);
});

it('applies companies-row quirks: drops slug, logo_path, stripe_*, switches inbox email off and captures old id', function () {
    $transformer = buildTransformer();

    $result = $transformer->transform('companies', [
        'id' => 13,
        'name' => 'Acme Inc.',
        'slug' => 'acme-inc',
        'logo_path' => 'company-logos/abc.png',
        'stripe_account_id' => 'acct_xyz',
        'stripe_connected_at' => '2026-01-01T00:00:00Z',
        'inbound_email_token' => 'source-forwarding-token',
        'inbound_email_enabled' => true,
        'currency_code' => 'CAD',
    ]);

    expect($result['old_id'])->toBe(13)
        ->and($result['row'])->not->toHaveKey('id')
        ->and($result['row'])->not->toHaveKey('slug')
        ->and($result['row'])->not->toHaveKey('stripe_account_id')
        ->and($result['row'])->not->toHaveKey('stripe_connected_at')
        ->and($result['row']['logo_path'])->toBeNull()
        ->and($result['row']['inbound_email_token'])->toBeNull()
        ->and($result['row']['inbound_email_enabled'])->toBeFalse()
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

it('restores company_api_keys rows as revoked keys with an unusable token hash', function () {
    $transformer = buildTransformer();

    // The bundle shape: the model hides token_hash, so the column is absent.
    $result = $transformer->transform('company_api_keys', [
        'id' => 1,
        'company_id' => 5,
        'label' => 'CI key',
        'last_four' => '1234',
        'revoked_at' => null,
        'token' => 'll_live_plaintext',
    ]);

    expect($result['row']['token_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($result['row']['revoked_at'])->not->toBeNull()
        ->and($result['row']['token'])->toBeNull()
        ->and($result['row']['last_four'])->toBe('1234')
        ->and($result['skip'])->toBeFalse();

    // A source hash is replaced, never carried over; each restore mints its own.
    $again = $transformer->transform('company_api_keys', [
        'id' => 2,
        'company_id' => 5,
        'label' => 'CI key',
        'token_hash' => 'hash-from-source-instance',
        'last_four' => '1234',
    ]);

    expect($again['row']['token_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($again['row']['token_hash'])->not->toBe($result['row']['token_hash']);
});

it('keeps the original revocation date on a restored company_api_keys row', function () {
    $transformer = buildTransformer();

    $result = $transformer->transform('company_api_keys', [
        'id' => 1,
        'company_id' => 5,
        'label' => 'Old key',
        'last_four' => '1234',
        'revoked_at' => '2026-03-01T12:00:00.000000Z',
    ]);

    expect($result['row']['revoked_at'])->toBe('2026-03-01T12:00:00.000000Z');
});

it('restores company_invitations rows with a code nobody holds, expiring the ones still open', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 12:00:00'));
    $transformer = buildTransformer();
    $sourceCode = str_repeat('a', 64);

    $invitation = fn (?string $expiresAt, ?string $acceptedAt): array => $transformer->transform('company_invitations', [
        'id' => 1,
        'company_id' => 5,
        'code' => $sourceCode,
        'expires_at' => $expiresAt,
        'accepted_at' => $acceptedAt,
    ])['row'];

    $open = $invitation('2026-11-01T12:00:00.000000Z', null);
    $neverExpires = $invitation(null, null);
    $accepted = $invitation('2026-11-01T12:00:00.000000Z', '2026-10-28T09:00:00.000000Z');
    $lapsed = $invitation('2026-10-01T12:00:00.000000Z', null);

    expect($open['code'])->toHaveLength(64)
        ->and([$open['code'], $neverExpires['code'], $accepted['code'], $lapsed['code']])->not->toContain($sourceCode)
        ->and($open['code'])->not->toBe($neverExpires['code'])
        ->and($open['expires_at'])->toEqual(now())
        ->and($neverExpires['expires_at'])->toEqual(now())
        ->and($accepted['expires_at'])->toBe('2026-11-01T12:00:00.000000Z')
        ->and($accepted['accepted_at'])->toBe('2026-10-28T09:00:00.000000Z')
        ->and($lapsed['expires_at'])->toBe('2026-10-01T12:00:00.000000Z');
});

it('restores mcp_write_proposals rows with a confirm token nobody holds, expiring the pending ones', function () {
    $transformer = buildTransformer();

    $rows = collect(McpProposalStatus::cases())->mapWithKeys(fn (McpProposalStatus $status): array => [
        $status->value => $transformer->transform('mcp_write_proposals', [
            'id' => 1,
            'company_id' => 5,
            'idempotency_key' => '01JSOURCEPROPOSALTOKEN0000',
            'status' => $status->value,
        ])['row'],
    ]);

    expect($rows->map(fn (array $row): string => $row['status'])->all())->toBe([
        McpProposalStatus::Pending->value => McpProposalStatus::Expired->value,
        McpProposalStatus::Confirmed->value => McpProposalStatus::Confirmed->value,
        McpProposalStatus::Expired->value => McpProposalStatus::Expired->value,
        McpProposalStatus::Rejected->value => McpProposalStatus::Rejected->value,
    ])
        ->and($rows->pluck('idempotency_key')->unique())->toHaveCount(4)
        ->and($rows->pluck('idempotency_key'))->not->toContain('01JSOURCEPROPOSALTOKEN0000');
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

it('remaps a refund\'s links to the payment it refunds and the invoice it came from', function () {
    $transformer = buildTransformer(idMap: [
        'customer_receipts' => [11 => 111],
        'invoices' => [7 => 77],
        'credit_memos' => [3 => 33],
    ]);

    // A card refund points at another receipt in the same table.
    $receipt = $transformer->transform('customer_receipts', [
        'id' => 12,
        'company_id' => 5,
        'refunded_receipt_id' => 11,
        'from_invoice_id' => 7,
        'credit_memo_id' => null,
    ]);

    expect($receipt['row']['refunded_receipt_id'])->toBe(111)
        ->and($receipt['row']['from_invoice_id'])->toBe(77)
        ->and($receipt['row']['credit_memo_id'])->toBeNull();

    $cheque = $transformer->transform('cheques', [
        'id' => 4,
        'company_id' => 5,
        'refunded_receipt_id' => 11,
        'from_invoice_id' => 7,
        'credit_memo_id' => 3,
    ]);

    expect($cheque['row']['refunded_receipt_id'])->toBe(111)
        ->and($cheque['row']['from_invoice_id'])->toBe(77)
        ->and($cheque['row']['credit_memo_id'])->toBe(33);
});
