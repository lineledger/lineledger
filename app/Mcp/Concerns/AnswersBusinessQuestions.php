<?php

namespace App\Mcp\Concerns;

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\User;
use App\Services\Reporting\ReportCalculator;
use App\Support\Access\PermissionSet;
use App\Support\Access\Requirement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use RuntimeException;

/**
 * Shared helpers for the read-only Business Q&A MCP tools. Each tool resolves the
 * tenant from the API-key-bound container, gates on a coarse ability scope, and
 * formats money in the company's home currency. Keeping these here lets every
 * tool's handle() stay a thin adapter over the existing reporting services.
 */
trait AnswersBusinessQuestions
{
    /**
     * The company bound by the AuthenticateApiKey middleware. Tools never filter
     * by company themselves — the CompanyScope global scope does that.
     */
    protected function company(): Company
    {
        $company = app()->bound('current_company') ? app('current_company') : null;

        if (! $company instanceof Company) {
            throw new RuntimeException('No company is bound to the current request.');
        }

        return $company;
    }

    /**
     * Returns an error Response when the bound API key lacks the given ability,
     * or null when the call is permitted. A key with no abilities has full access
     * (per CompanyApiKey::hasAbility), so an unbound key is treated as allowed.
     */
    protected function requireAbility(string $ability): ?Response
    {
        $key = app()->bound('current_api_key') ? app('current_api_key') : null;

        if ($key instanceof CompanyApiKey && ! $key->hasAbility($ability)) {
            return Response::error("This API key is not permitted to perform the \"{$ability}\" action.");
        }

        return null;
    }

    /**
     * Returns an error Response unless whoever is calling may take the action
     * in the activity (any one of several activities), in the same terms as
     * the web app; null when permitted.
     *
     * An OAuth connection acts as the signed-in member. An API-key connection
     * acts with the permissions of the person who created the key (keys with
     * no recorded creator keep their ability scopes as the only limit), so a
     * key never reads or writes more than its creator could — the same cap
     * EnsureApiPermission applies to /api/v1.
     *
     * @param  Activity|list<Activity>  $activity
     */
    protected function requirePermission(Activity|array $activity, PermissionAction $action = PermissionAction::View): ?Response
    {
        $permissions = $this->actorPermissions();

        if ($permissions === null) {
            return null;
        }

        $activities = is_array($activity) ? $activity : [$activity];
        $requirements = array_map(fn (Activity $a): Requirement => new Requirement($a, $action), $activities);

        if ($permissions->allowsAny($requirements)) {
            return null;
        }

        $labels = implode('" or "', array_map(fn (Activity $a): string => $a->label(), $activities));

        $verb = mb_strtolower($action->label());

        return Response::error("You do not have access to {$verb} \"{$labels}\" in this company.");
    }

    /**
     * Whether this connection may see balances in the activity (View
     * balance): tools that list accounts or contacts leave balances out
     * otherwise rather than refusing the whole answer.
     */
    protected function actorSeesBalances(Activity $activity): bool
    {
        $permissions = $this->actorPermissions();

        return $permissions === null || $permissions->canViewBalances($activity);
    }

    /**
     * Whether this connection may see payroll detail (employee names and
     * memos on payroll ledger lines): only one that can open pay runs, as in
     * the web app (PayrollDetail). Others read such lines as "Payroll".
     */
    protected function actorSeesPayrollDetail(): bool
    {
        $permissions = $this->actorPermissions();

        return $permissions === null || $permissions->seesPayrollDetail();
    }

    /**
     * The permissions this connection acts with, or null when nothing beyond
     * the key's scopes applies (a key with no recorded creator).
     */
    private function actorPermissions(): ?PermissionSet
    {
        $company = $this->company();
        $user = Auth::guard('api')->user();

        if ($user instanceof User) {
            return $user->permissions($company);
        }

        $key = app()->bound('current_api_key') ? app('current_api_key') : null;

        if ($key instanceof CompanyApiKey && $key->created_by_user_id !== null) {
            return User::query()->find($key->created_by_user_id)?->permissions($company) ?? PermissionSet::none();
        }

        return null;
    }

    /**
     * Human-readable currency in the company's home currency, e.g. "$1,234.56".
     */
    protected function money(int $cents): string
    {
        return Money::fromCents($cents, $this->company()->currency_code)->format();
    }

    /**
     * Human-readable currency in a document's own currency (an invoice, bill
     * or payment may be in a foreign currency); the home currency when the
     * document carries none.
     */
    protected function moneyIn(int $cents, ?string $currency): string
    {
        $code = $currency !== null && $currency !== '' ? $currency : $this->company()->currency_code;

        return Money::fromCents($cents, $code)->format();
    }

    /**
     * Narrow $query to rows whose $column contains $term, matching the term
     * literally: a "%" or "_" the caller typed is not a wildcard. The ESCAPE
     * clause is spelled out because SQLite has no default escape character
     * (a bare backslash escape only works on MySQL). $column is always a
     * column name written in code, never caller input.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function whereContains(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term));

        return $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", ['%'.$escaped.'%'], $boolean);
    }

    /**
     * Resolve one customer from what a caller typed: a numeric API id, an
     * exact display name, or a name fragment that matches exactly one
     * customer. Returns the contact, or a plain-language message (no match,
     * or several to choose from, each with its API id).
     */
    protected function resolveCustomer(string $needle): Contact|string
    {
        $needle = trim($needle);

        if ($needle === '') {
            return 'Please name the customer (a name, a fragment of one, or its API id).';
        }

        $customers = fn (): Builder => Contact::query()->where('is_customer', true);

        if (ctype_digit($needle) && ($byId = $customers()->find((int) $needle)) instanceof Contact) {
            return $byId;
        }

        $exact = $customers()->whereRaw('LOWER(display_name) = ?', [mb_strtolower($needle)])->get();

        if ($exact->count() === 1) {
            return $exact->first();
        }

        $matches = $customers()
            ->where(fn (Builder $q) => $this->whereContains($this->whereContains($q, 'display_name', $needle), 'company_name', $needle, 'or'))
            ->orderBy('display_name')
            ->limit(11)
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            return "No customer matched \"{$needle}\".";
        }

        $list = $matches->take(10)
            ->map(fn (Contact $c): string => "- {$c->display_name} (API id {$c->id})")
            ->implode("\n");

        $more = $matches->count() > 10 ? "\n…and more. Narrow the name, or pass the API id." : '';

        return "Several customers match \"{$needle}\". Pass one of these API ids, or a more specific name:\n{$list}{$more}";
    }

    /**
     * Resolve a reporting window from either an explicit start/end (ISO dates) or
     * a friendly `period` keyword. Fiscal-year-aware via ReportCalculator so a
     * company with a non-January year start gets the right "this year" window.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, label: string}
     */
    protected function resolvePeriod(Request $request): array
    {
        $company = $this->company();
        $today = $company->currentDateTime()->startOfDay();

        $start = $request->get('start');
        $end = $request->get('end');

        if (is_string($start) && $start !== '' && is_string($end) && $end !== '') {
            return [
                'start' => CarbonImmutable::parse($start)->startOfDay(),
                'end' => CarbonImmutable::parse($end)->startOfDay(),
                'label' => CarbonImmutable::parse($start)->toFormattedDateString().' – '.CarbonImmutable::parse($end)->toFormattedDateString(),
            ];
        }

        $calculator = app(ReportCalculator::class);
        $fiscalStart = $calculator->fiscalYearStart($company, $today);
        $period = (string) $request->get('period', 'ytd');

        return match ($period) {
            'this_month' => $this->window($today->startOfMonth(), $today, 'This month'),
            'last_month' => $this->window(
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth()->startOfDay(),
                'Last month',
            ),
            'this_quarter' => $this->window($today->startOfQuarter(), $today, 'This quarter'),
            'last_quarter' => $this->window(
                $today->subQuarterNoOverflow()->startOfQuarter(),
                $today->subQuarterNoOverflow()->endOfQuarter()->startOfDay(),
                'Last quarter',
            ),
            'this_year' => $this->window($fiscalStart, $today, 'This fiscal year'),
            'last_year' => $this->window(
                $fiscalStart->subYear(),
                $fiscalStart->subDay(),
                'Last fiscal year',
            ),
            default => $this->window($fiscalStart, $today, 'Fiscal year to date'),
        };
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, label: string}
     */
    private function window(CarbonImmutable $start, CarbonImmutable $end, string $label): array
    {
        return ['start' => $start, 'end' => $end, 'label' => $label];
    }
}
