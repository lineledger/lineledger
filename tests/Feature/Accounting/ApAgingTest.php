<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\BillPoster;
use Carbon\CarbonImmutable;

it('groups open vendor bills into aging buckets', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    $vendor = Contact::create(['display_name' => 'Aging Vendor', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $create = function (string $no, CarbonImmutable $date, CarbonImmutable $due, int $cents) use ($vendor, $expense) {
        $b = Bill::create([
            'contact_id' => $vendor->id,
            'bill_type' => BillType::Vendor,
            'bill_no' => $no,
            'bill_date' => $date,
            'due_date' => $due,
        ]);

        $b->lines()->create([
            'account_id' => $expense->id,
            'description' => 'x',
            'quantity' => '1',
            'unit_price_cents' => $cents,
            'line_subtotal_cents' => $cents,
            'line_tax_cents' => 0,
            'line_total_cents' => $cents,
            'line_order' => 0,
        ]);

        app(BillPoster::class)->post($b);
    };

    $today = CarbonImmutable::create(2026, 5, 20);

    $create('A', $today->subDays(5), $today->addDays(10), 1000);  // current
    $create('B', $today->subDays(15), $today->subDays(5), 2000);   // 1-30
    $create('C', $today->subDays(60), $today->subDays(45), 3000);  // 31-60
    $create('D', $today->subDays(120), $today->subDays(100), 5000); // 90+

    $this->actingAs($user);

    $response = $this->get(route('reports.ap-aging', ['company' => $company->slug, 'as_of' => $today->toDateString()]));

    $response->assertOk();
    $response->assertSee('Aging Vendor');
    $response->assertSee('10.00');
    $response->assertSee('20.00');
    $response->assertSee('30.00');
    $response->assertSee('50.00');
    $response->assertSee('110.00');

    app()->forgetInstance('current_company');
});
