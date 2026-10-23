<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    // Positive cents = deposit (debit the bank), negative = payment (credit it).
    $this->post = function (string $no, string $date, int $cents, string $memo): JournalEntry {
        $entry = JournalEntry::create(['entry_no' => $no, 'entry_date' => $date, 'memo' => $memo]);
        $entry->lines()->createMany([
            ['account_id' => $this->bank->id, 'debit_cents' => max($cents, 0), 'credit_cents' => max(-$cents, 0), 'line_order' => 0],
            ['account_id' => $this->income->id, 'debit_cents' => max(-$cents, 0), 'credit_cents' => max($cents, 0), 'line_order' => 1],
        ]);

        return app(JournalPoster::class)->post($entry);
    };

    ($this->post)('JE-A', '2025-01-10', 10000, 'Bravo deposit');   // balance 100.00
    ($this->post)('JE-B', '2025-02-10', -2500, 'Alpha payment');   // balance  75.00
    ($this->post)('JE-C', '2025-03-10', 50000, 'Charlie deposit'); // balance 575.00
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function registerFor($test)
{
    return Livewire::test('pages::banking.register', ['company' => $test->company])
        ->set('account_id', $test->bank->id);
}

function registerMemos($register): array
{
    return $register->instance()->lines->map(fn ($l) => $l->journalEntry->memo)->all();
}

it('lists the newest transactions first by default', function () {
    $register = registerFor($this);

    expect($register->get('sortField'))->toBe('date')
        ->and($register->get('sortDir'))->toBe('desc')
        ->and(registerMemos($register))->toBe(['Charlie deposit', 'Alpha payment', 'Bravo deposit']);
});

it('reverses the date order when the Date header is clicked again', function () {
    $register = registerFor($this)->call('sortBy', 'date');

    expect($register->get('sortDir'))->toBe('asc')
        ->and(registerMemos($register))->toBe(['Bravo deposit', 'Alpha payment', 'Charlie deposit']);
});

it('sorts by entry number, memo, payment and deposit', function () {
    expect(registerMemos(registerFor($this)->call('sortBy', 'entry')))
        ->toBe(['Charlie deposit', 'Alpha payment', 'Bravo deposit']);

    expect(registerMemos(registerFor($this)->call('sortBy', 'memo')))
        ->toBe(['Alpha payment', 'Bravo deposit', 'Charlie deposit']);

    expect(registerMemos(registerFor($this)->call('sortBy', 'payment'))[0])
        ->toBe('Alpha payment');

    expect(registerMemos(registerFor($this)->call('sortBy', 'deposit')))
        ->toBe(['Charlie deposit', 'Bravo deposit', 'Alpha payment']);
});

it('ignores an unknown sort field', function () {
    $register = registerFor($this)->call('sortBy', 'id; drop table users');

    expect($register->get('sortField'))->toBe('date')
        ->and(registerMemos($register))->toBe(['Charlie deposit', 'Alpha payment', 'Bravo deposit']);
});

it('shows the true running balance whatever the sort order', function () {
    foreach ([[], ['date'], ['deposit'], ['memo']] as $clicks) {
        $register = registerFor($this);
        foreach ($clicks as $field) {
            $register->call('sortBy', $field);
        }

        $byMemo = [];
        foreach ($register->instance()->lines as $line) {
            $byMemo[$line->journalEntry->memo] = $register->instance()->runningBalances[$line->id];
        }

        ksort($byMemo);

        expect($byMemo)->toBe([
            'Alpha payment' => 7500,
            'Bravo deposit' => 10000,
            'Charlie deposit' => 57500,
        ]);
    }

    registerFor($this)->assertSeeHtml('data-test="register-balance"')->assertSee('575.00');
});

it('keeps running balances true when cleared rows are hidden', function () {
    JournalLine::query()
        ->where('account_id', $this->bank->id)
        ->where('debit_cents', 10000)
        ->update(['cleared_at' => now()]);

    $register = registerFor($this)->set('showCleared', false);
    $balances = $register->instance()->runningBalances;

    expect(registerMemos($register))->toBe(['Charlie deposit', 'Alpha payment'])
        ->and(array_values($balances))->toEqualCanonicalizing([7500, 57500]);
});

it('paginates the register', function () {
    for ($i = 1; $i <= 55; $i++) {
        ($this->post)('JE-P'.$i, '2025-04-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT), 100, 'Bulk '.$i);
    }

    $register = registerFor($this);

    expect($register->instance()->lines)->toHaveCount(50)
        ->and($register->instance()->lines->total())->toBe(58);
});
