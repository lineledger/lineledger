<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ReportGroupLine;
use App\Support\Reporting\CashFlowBucket;

/**
 * Build an in-memory account (no DB) carrying the type/subtype the casts expect.
 */
function bucketAccount(AccountType $type, AccountSubtype $subtype, ?string $override = null): Account
{
    return new Account([
        'type' => $type,
        'subtype' => $subtype,
        'cash_flow_activity' => $override,
    ]);
}

/**
 * Build an in-memory combined report-group line (no DB), the same way.
 */
function bucketLine(AccountType $type, ?AccountSubtype $subtype, ?string $override = null): ReportGroupLine
{
    return new ReportGroupLine([
        'type' => $type,
        'subtype' => $subtype,
        'cash_flow_activity' => $override,
    ]);
}

it('classifies a combined line by type/subtype when no override is set', function () {
    expect(CashFlowBucket::forLine(bucketLine(AccountType::Liability, AccountSubtype::LongTermLiability)))->toBe('financing')
        ->and(CashFlowBucket::forLine(bucketLine(AccountType::Asset, null)))->toBe('operating');
});

it('honors a per-line override when the combined line is its own activity line', function () {
    $line = bucketLine(AccountType::Asset, AccountSubtype::FixedAsset, 'financing');

    expect(CashFlowBucket::forLine($line))->toBe('financing');
});

it('ignores a per-line override on a bank line so combined cash stays excluded', function () {
    $line = bucketLine(AccountType::Asset, AccountSubtype::Bank, 'investing');

    expect(CashFlowBucket::forLine($line))->toBeNull();
});

it('ignores a per-line override on a P&L line so it stays in combined net income', function () {
    $line = bucketLine(AccountType::Expense, AccountSubtype::Expense, 'financing');

    expect(CashFlowBucket::forLine($line))->toBeNull();
});

it('normalizes an override so only a real re-route is stored', function () {
    // A real re-route is kept.
    expect(CashFlowBucket::normalizeOverride(AccountType::Asset, AccountSubtype::FixedAsset, 'financing'))->toBe('financing')
        // Restating the default stores nothing.
        ->and(CashFlowBucket::normalizeOverride(AccountType::Asset, AccountSubtype::FixedAsset, 'investing'))->toBeNull()
        // Rows with no activity of their own never carry one.
        ->and(CashFlowBucket::normalizeOverride(AccountType::Asset, AccountSubtype::Bank, 'financing'))->toBeNull()
        ->and(CashFlowBucket::normalizeOverride(AccountType::Income, AccountSubtype::Income, 'operating'))->toBeNull()
        // Unknown values and "no choice" are null.
        ->and(CashFlowBucket::normalizeOverride(AccountType::Asset, AccountSubtype::FixedAsset, 'bogus'))->toBeNull()
        ->and(CashFlowBucket::normalizeOverride(AccountType::Asset, AccountSubtype::FixedAsset, null))->toBeNull();
});

it('classifies an account by type/subtype when no override is set', function () {
    $account = bucketAccount(AccountType::Liability, AccountSubtype::LongTermLiability);

    expect(CashFlowBucket::for($account))->toBe('financing');
});

it('honors a per-account override when the account is its own activity line', function () {
    $account = bucketAccount(AccountType::Liability, AccountSubtype::LongTermLiability, 'operating');

    expect(CashFlowBucket::for($account))->toBe('operating');
});

it('ignores an override on a bank account so cash stays excluded', function () {
    $account = bucketAccount(AccountType::Asset, AccountSubtype::Bank, 'investing');

    expect(CashFlowBucket::for($account))->toBeNull();
});

it('ignores an override on a P&L account so it stays in net income', function () {
    $account = bucketAccount(AccountType::Income, AccountSubtype::Income, 'financing');

    expect(CashFlowBucket::for($account))->toBeNull();
});

it('exposes activity labels sourced from the CashFlowActivity enum', function () {
    expect(CashFlowBucket::labels())->toBe([
        'operating' => 'Operating Activities',
        'investing' => 'Investing Activities',
        'financing' => 'Financing Activities',
    ]);
});
