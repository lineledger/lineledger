<?php

namespace App\Mcp\Concerns;

use App\Enums\Section;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\User;
use App\Services\Reporting\ReportCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
     * Returns an error Response when an OAuth-authenticated member lacks access to
     * the given company Section (mirroring the web app's EnsureSectionAccess), or
     * null when permitted.
     *
     * On the API-key connection path there is no per-user Section concept —
     * authorization there is handled by {@see requireAbility()} and the key's
     * ability scopes — so this is a no-op when no OAuth user is bound. This is the
     * authorization gate for the OAuth path: bare company membership is NOT enough,
     * the member must also be granted the relevant section.
     */
    protected function requireSection(Section $section): ?Response
    {
        return $this->denyUnlessAnySection($section);
    }

    /**
     * Like {@see requireSection()} but passes when the member has access to ANY of
     * the given sections — for tools whose data spans more than one area (e.g. a
     * contact lookup that covers both customers and vendors).
     */
    protected function requireAnySection(Section ...$sections): ?Response
    {
        return $this->denyUnlessAnySection(...$sections);
    }

    private function denyUnlessAnySection(Section ...$sections): ?Response
    {
        $user = Auth::guard('api')->user();

        // No OAuth user → API-key path; section access does not apply there.
        if (! $user instanceof User) {
            return null;
        }

        $company = $this->company();

        foreach ($sections as $section) {
            if ($user->canAccessSection($company, $section)) {
                return null;
            }
        }

        $labels = implode('" or "', array_map(fn (Section $s): string => $s->label(), $sections));

        return Response::error("You do not have access to the \"{$labels}\" section of this company.");
    }

    /**
     * Human-readable currency in the company's home currency, e.g. "$1,234.56".
     */
    protected function money(int $cents): string
    {
        return Money::fromCents($cents, $this->company()->currency_code)->format();
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
