<?php

namespace App\Services\Deadlines;

use App\Enums\Activity;
use App\Enums\DeadlineStatus;
use App\Enums\PermissionAction;
use App\Models\Company;
use App\Models\DeadlineOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The occurrence queries the Deadlines page, the dashboard card, the sidebar
 * badge, the digest email and the MCP tool share, all in the organization's
 * own dates. Relies on the company scope: callers run with the organization
 * bound (requests always are; the notification job binds it).
 */
final class DeadlineFeed
{
    /** The assignee filter values besides a member's id. */
    public const string MINE = 'me';

    public const string EVERYONE = 'all';

    public const string UNASSIGNED = 'unassigned';

    public function __construct(public readonly Company $company) {}

    public static function for(Company $company): self
    {
        return new self($company);
    }

    /**
     * The organization's calendar date today, as midnight in the app's timezone
     * — the same footing as the stored `date` columns, so comparisons and day
     * counts against them are exact.
     */
    public function today(): CarbonImmutable
    {
        return self::dateOf($this->company);
    }

    public static function dateOf(Company $company): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $company->currentDateTime()->toDateString());
    }

    /**
     * Occurrences of deadlines that haven't been archived, deadline loaded.
     *
     * @return Builder<DeadlineOccurrence>
     */
    public function query(): Builder
    {
        return DeadlineOccurrence::query()
            ->ofCurrentDeadlines()
            ->with(['deadline', 'assignee', 'completedBy']);
    }

    /**
     * Narrows to an assignee filter: "me", "all", "unassigned" or a member id.
     *
     * @param  Builder<DeadlineOccurrence>  $query
     * @return Builder<DeadlineOccurrence>
     */
    public function assignedTo(Builder $query, string $filter, ?User $viewer): Builder
    {
        return match (true) {
            $filter === self::EVERYONE, $filter === '' => $query,
            $filter === self::UNASSIGNED => $query->whereNull('assigned_user_id'),
            $filter === self::MINE => $query->where('assigned_user_id', $viewer->id ?? 0),
            ctype_digit($filter) => $query->where('assigned_user_id', (int) $filter),
            default => $query,
        };
    }

    /**
     * Open occurrences that are overdue or due within `$days`, oldest first:
     * the viewer's own, plus unassigned ones when `$includeUnassigned`.
     *
     * @return Collection<int, DeadlineOccurrence>
     */
    public function needingAttention(User $user, int $days, bool $includeUnassigned, ?int $limit = null): Collection
    {
        return $this->attentionQuery($user, $days, $includeUnassigned)
            ->orderBy('due_date')
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $q) => $q->limit((int) $limit))
            ->get();
    }

    /**
     * The sidebar badge: how many open occurrences need the viewer's
     * attention in the next week, and whether any is overdue.
     *
     * @return array{count: int, overdue: bool}
     */
    public function badge(User $user): array
    {
        $query = $this->attentionQuery($user, DeadlineOccurrence::DUE_SOON_DAYS, $this->mayComplete($user))
            ->setEagerLoads([]);

        $count = (clone $query)->count();

        return [
            'count' => $count,
            'overdue' => $count > 0
                && (clone $query)->where('due_date', '<', $this->today()->toDateString())->exists(),
        ];
    }

    /**
     * Whether the viewer can mark deadlines done here (and so owns the
     * unassigned ones too).
     */
    public function mayComplete(User $user): bool
    {
        return $user->permissions($this->company)->allows(Activity::Deadlines, PermissionAction::Modify);
    }

    /**
     * @return Builder<DeadlineOccurrence>
     */
    private function attentionQuery(User $user, int $days, bool $includeUnassigned): Builder
    {
        return $this->query()
            ->where('status', DeadlineStatus::Open->value)
            ->where('due_date', '<=', $this->today()->addDays($days)->toDateString())
            ->where(function (Builder $q) use ($user, $includeUnassigned): void {
                $q->where('assigned_user_id', $user->id);

                if ($includeUnassigned) {
                    $q->orWhereNull('assigned_user_id');
                }
            });
    }
}
