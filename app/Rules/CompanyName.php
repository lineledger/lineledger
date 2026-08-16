<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Routing\Route as RouteElement;
use Illuminate\Support\Facades\Route;
use Illuminate\Translation\PotentiallyTranslatedString;

class CompanyName implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = strtolower(trim($value));

        if (in_array($name, $this->reservedNames(), true)) {
            $fail(__('This company name is reserved and cannot be used.'));
        }
    }

    /**
     * @return array<int, string>
     */
    protected function reservedNames(): array
    {
        return once(fn () => collect($this->routesPrefixes())
            ->merge([
                '300', '302', '400', '401', '402', '403', '404', '405', '406', '407',
                '408', '409', '410', '411', '412', '413', '414', '415', '416', '417',
                '418', '419', '420', '421', '422', '423', '424', '425', '426', '427',
                '428', '429', '430', '431', '500', '501', '502', '503', '504', '505',
                '506', '507', '508', '509', '510', '511', '693', '694', '695', '900',
                'about', 'account', 'accounts', 'admin', 'api', 'app', 'apps',
                'auth', 'banking', 'billing', 'bills', 'cash', 'cheques', 'checks',
                'companies', 'company', 'contact', 'customer', 'customers', 'dashboard',
                'deposits', 'docs', 'documentation', 'employees', 'expenses', 'help',
                'home', 'inbox', 'index', 'invitations', 'invoice', 'invoices',
                'journal', 'journals', 'ledger', 'login', 'logout', 'mail', 'members',
                'new', 'oauth', 'payable', 'payments', 'preferences', 'pricing',
                'profile', 'reconcile', 'register', 'reimbursements', 'reports',
                'search', 'security', 'sessions', 'settings', 'sign-in', 'sign-up',
                'signin', 'signup', 'status', 'support', 'tax', 'taxes', 'transactions',
                'two-factor', 'user', 'users', 'vendor', 'vendors', 'verify',
                'webhooks', 'www',
            ])
            ->unique()
            ->sort()
            ->values()
            ->toArray());
    }

    /**
     * @return array<int, string>
     */
    protected function routesPrefixes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn (RouteElement $route) => $route->uri)
            ->map(fn (string $uri) => explode('/', $uri)[0])
            ->reject(fn (string $uri) => str_contains($uri, '{'))
            ->filter(fn (string $uri) => $uri !== '')
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }
}
