<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
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
