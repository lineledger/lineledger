<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Notifications\Reports\ReportEmailNotification;
use App\Services\Reporting\Render\RenderedArtifact;
use App\Services\Reporting\Render\ReportRenderer;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;

/**
 * An emailed report renders on the queue, where nobody is signed in. It
 * renders as the person who sent it (or owns the schedule), so it shows what
 * they may see — payroll names on the general ledger, balances — and the
 * worker is left as it was afterwards.
 */
it('renders an emailed report as its sender, then signs them out again', function () {
    $company = Company::factory()->create();
    $sender = User::factory()->create();
    $company->memberships()->create(['user_id' => $sender->id, 'role' => CompanyRole::Owner]);

    $renderedAs = [];
    app()->instance(ReportRenderer::class, new class($renderedAs) extends ReportRenderer
    {
        /** @param  list<int|null>  $seen */
        public function __construct(private array &$seen) {}

        public function render(Company $company, string $reportKey, array $settings, string $format, bool $resolvePresets = false): RenderedArtifact
        {
            $this->seen[] = Auth::id();

            return new RenderedArtifact('bytes', 'report.'.$format, 'application/octet-stream');
        }
    });

    $notification = fn (?int $senderId) => new ReportEmailNotification(
        company: $company,
        reportKey: 'general-ledger',
        reportLabel: 'General ledger',
        settings: [],
        attachXlsx: true,
        senderUserId: $senderId,
    );

    $notification($sender->id)->toMail(new AnonymousNotifiable);
    expect($renderedAs)->toBe([$sender->id, $sender->id])
        ->and(Auth::hasUser())->toBeFalse();

    $notification(null)->toMail(new AnonymousNotifiable);
    expect($renderedAs)->toBe([$sender->id, $sender->id, null, null]);
});
