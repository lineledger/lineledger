<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/*
 * The migration that moves accounts off the GIFI codes the first catalog
 * mislabelled. Members picked a code by its label, so each account must land
 * on the code CRA uses for that label, once: 8620 ("Insurance") goes to 8690
 * and stops there, even though 8690 ("Interest and bank charges") moves on.
 */

function runGifiCorrectionMigration(): void
{
    (require database_path('migrations/2026_11_16_120000_correct_gifi_codes_on_accounts.php'))->up();
}

/** Give an account a raw stored code, as the old catalog would have saved it. */
function storeOldGifiCode(Account $account, ?string $code): Account
{
    DB::table('accounts')->where('id', $account->id)->update(['gifi_code' => $code]);

    return $account;
}

it('moves each account once, onto the code CRA uses for the label it showed', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $expenses = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->take(4)->get();
    $insurance = storeOldGifiCode($expenses[0], '8620');
    $interest = storeOldGifiCode($expenses[1], '8690');
    $longTermInterest = storeOldGifiCode($expenses[2], '8710');
    $untouched = storeOldGifiCode($expenses[3], '9270');
    $dueFrom = storeOldGifiCode(Account::query()->where('subtype', AccountSubtype::Bank->value)->first(), '2589');
    $retained = Account::query()->where('subtype', AccountSubtype::RetainedEarnings->value)->sole();
    storeOldGifiCode($retained, '3849');
    $unmapped = storeOldGifiCode(Account::query()->where('subtype', AccountSubtype::Income->value)->first(), null);

    runGifiCorrectionMigration();

    expect($insurance->fresh()->gifi_code)->toBe('8690')
        ->and($interest->fresh()->gifi_code)->toBe('8710')
        ->and($longTermInterest->fresh()->gifi_code)->toBe('8714')
        ->and($untouched->fresh()->gifi_code)->toBe('9270')
        ->and($dueFrom->fresh()->gifi_code)->toBe('2420')
        ->and($retained->fresh()->gifi_code)->toBe('3600')
        ->and($unmapped->fresh()->gifi_code)->toBeNull();
});

it('corrects every organization’s accounts', function () {
    $first = Company::factory()->create();
    $second = Company::factory()->create();

    $accounts = collect([$first, $second])->map(function (Company $company) {
        app()->instance('current_company', $company);

        return storeOldGifiCode(Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first(), '9367');
    });

    app()->forgetInstance('current_company');

    runGifiCorrectionMigration();

    foreach ($accounts as $account) {
        expect(Account::withoutGlobalScopes()->find($account->id)->gifi_code)->toBe('8670');
    }
});
