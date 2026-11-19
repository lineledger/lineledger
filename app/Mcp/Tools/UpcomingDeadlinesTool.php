<?php

namespace App\Mcp\Tools;

use App\Enums\Activity;
use App\Enums\DeadlineStatus;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\DeadlineOccurrence;
use App\Services\Deadlines\DeadlineFeed;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpcomingDeadlinesTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Upcoming deadlines';

    protected string $description = 'Answers "what\'s due soon?" — the company\'s filing and payment deadlines (GST/HST and PST returns, payroll remittances, instalments, information returns, licence renewals) that are overdue or due in the next N days, with who each is assigned to. Optionally includes ones already done in that window, with who completed them and when. Read-only: deadlines are marked done in the LineLedger app.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('tax:read')) {
            return $denied;
        }

        if ($denied = $this->requirePermission(Activity::Deadlines)) {
            return $denied;
        }

        $days = max(1, min((int) ($request->get('days_ahead') ?? 30), 366));
        $includeDone = (bool) ($request->get('include_done') ?? false);

        $feed = DeadlineFeed::for($this->company());
        $today = $feed->today();
        $until = $today->addDays($days);

        $occurrences = $feed->query()
            ->when(
                $includeDone,
                fn ($q) => $q->where(fn ($q) => $q->where('status', DeadlineStatus::Open->value)
                    ->orWhereBetween('due_date', [$today->subDays($days)->toDateString(), $until->toDateString()])),
                fn ($q) => $q->where('status', DeadlineStatus::Open->value),
            )
            ->where('due_date', '<=', $until->toDateString())
            ->orderBy('due_date')
            ->limit(200)
            ->get();

        $name = $this->company()->name;

        if ($occurrences->isEmpty()) {
            return Response::text("{$name} has no deadlines overdue or due in the next {$days} days.");
        }

        $lines = ["Deadlines for {$name} (today is {$today->toDateString()}; looking {$days} days ahead):", ''];

        foreach ($occurrences as $occurrence) {
            $lines[] = '• '.$this->line($occurrence, $today->toDateString());
        }

        return Response::text(implode("\n", $lines));
    }

    private function line(DeadlineOccurrence $occurrence, string $today): string
    {
        $deadline = $occurrence->deadline;
        $due = $occurrence->due_date->toDateString();
        $period = $occurrence->periodLabel();

        $status = match ($occurrence->status) {
            DeadlineStatus::Done => 'done '.($occurrence->completed_on?->toDateString() ?? '').' by '.($occurrence->completedBy->name ?? 'unknown')
                .($occurrence->amount_cents !== null ? ', '.$this->money($occurrence->amount_cents) : ''),
            DeadlineStatus::Skipped => 'not required'.($occurrence->skipped_reason ? " ({$occurrence->skipped_reason})" : ''),
            DeadlineStatus::Open => $due < $today ? 'OVERDUE' : 'open',
        };

        return "{$due} — {$deadline->title}"
            .($period !== null ? " ({$period})" : '')
            .($deadline->agency_name ? " · {$deadline->agency_name}" : '')
            .' · '.($occurrence->assignee->name ?? 'unassigned')
            ." · {$status}";
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days_ahead' => $schema->integer()
                ->description('How many days ahead to look (default 30, max 366). Overdue deadlines are always included.'),
            'include_done' => $schema->boolean()
                ->description('Also list deadlines in the window that were already done or marked not required (default false).'),
        ];
    }
}
