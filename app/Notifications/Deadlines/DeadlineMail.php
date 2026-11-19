<?php

namespace App\Notifications\Deadlines;

use App\Models\Company;
use App\Models\DeadlineOccurrence;
use App\Support\Deadlines\DeadlineDates;
use App\Support\Http\CanonicalUrl;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The parts the deadline emails share: the link that opens an occurrence on
 * the Deadlines page, and the lines describing one.
 */
final class DeadlineMail
{
    public static function link(Company $company, ?DeadlineOccurrence $occurrence = null): string
    {
        $parameters = ['company' => $company, 'view' => 'list'];

        if ($occurrence !== null) {
            $parameters['occurrence'] = $occurrence->id;
        }

        return CanonicalUrl::route('deadlines.index', $parameters);
    }

    /**
     * The one-line summary used in digests: "Mon, Nov 30 — GST/HST return (Aug–Oct 2026)".
     */
    public static function summary(DeadlineOccurrence $occurrence): string
    {
        $period = $occurrence->periodLabel();

        return DeadlineDates::shortWithWeekday($occurrence->due_date).' — '.$occurrence->deadline->title
            .($period !== null ? ' ('.$period.')' : '');
    }

    /**
     * The detail lines for a single-occurrence email.
     */
    public static function details(MailMessage $mail, Company $company, DeadlineOccurrence $occurrence): MailMessage
    {
        $deadline = $occurrence->deadline;
        $period = $occurrence->periodLabel();

        return $mail
            ->line(__('**:title** is due :date.', [
                'title' => $deadline->title,
                'date' => DeadlineDates::long($occurrence->due_date),
            ]))
            ->when($period !== null, fn (MailMessage $m) => $m->line(__('Period: :period', ['period' => $period])))
            ->when(filled($deadline->agency_name), fn (MailMessage $m) => $m->line(__('Agency: :agency', ['agency' => $deadline->agency_name])))
            ->when($deadline->expected_amount_cents !== null, fn (MailMessage $m) => $m->line(__('Usual amount: :amount', [
                'amount' => Money::fromCents((int) $deadline->expected_amount_cents, (string) $company->currency_code)->format(),
            ])));
    }
}
