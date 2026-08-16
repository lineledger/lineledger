<?php

use App\Enums\Industry;

test('every industry recommends the full set of feature keys', function () {
    $keys = ['inventory', 'employees', 'fixed_assets', 'estimates', 'sales_orders', 'recurring_invoices', 'recurring_bills', 'classes', 'locations', 'budgets', 'membership', 'fundraising'];

    foreach (Industry::cases() as $industry) {
        expect(array_keys($industry->recommendedFeatures()))->toEqualCanonicalizing($keys);
    }
});

test('recommended features match the per-industry suggestions', function () {
    $enabled = fn (Industry $i) => array_keys(array_filter($i->recommendedFeatures()));

    expect($enabled(Industry::General))->toBe([]);
    expect($enabled(Industry::Freelancer))->toBe([]);
    expect($enabled(Industry::Contractor))->toEqualCanonicalizing(['inventory', 'fixed_assets', 'estimates', 'employees']);
    expect($enabled(Industry::Manufacturing))->toEqualCanonicalizing(['inventory', 'fixed_assets', 'estimates', 'employees']);
    expect($enabled(Industry::NonProfit))->toEqualCanonicalizing(['employees', 'fixed_assets', 'membership', 'fundraising']);
    expect($enabled(Industry::RealEstate))->toEqualCanonicalizing(['employees', 'fixed_assets']);
    expect($enabled(Industry::Retail))->toEqualCanonicalizing(['inventory', 'fixed_assets']);
    expect($enabled(Industry::ProfessionalServices))->toEqualCanonicalizing(['employees', 'estimates']);
    expect($enabled(Industry::HealthWellness))->toEqualCanonicalizing(['recurring_invoices', 'membership']);
    expect($enabled(Industry::Restaurant))->toEqualCanonicalizing(['inventory', 'fixed_assets', 'employees']);
});
