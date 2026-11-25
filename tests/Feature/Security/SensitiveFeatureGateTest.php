<?php

use App\Actions\Companies\Members\InviteMember;
use App\Actions\Portal\RequestPortalLoginLink;
use App\Actions\Recurring\SaveRecurringDocument;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InsightCategory;
use App\Enums\InsightSource;
use App\Enums\InvoiceStatus;
use App\Enums\SensitiveFeature;
use App\Exceptions\Security\TwoFactorRequiredException;
use App\Jobs\Inbox\ProcessInboxItem;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\CompanyInvitation;
use App\Models\Contact;
use App\Models\InboxItem;
use App\Models\Invoice;
use App\Models\InvoiceReminderLog;
use App\Models\PortalLoginLink;
use App\Models\User;
use App\Notifications\Portal\PortalLoginLinkNotification;
use App\Notifications\Sales\InvoiceReminderNotification;
use App\Notifications\Sales\InvoiceSharedNotification;
use App\Services\Inbox\OCR\Contracts\ReceiptIntelligence;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Contracts\InsightNarrator;
use App\Services\Insights\DailyInsightGenerator;
use App\Services\Insights\InsightCandidate;
use App\Services\Insights\InsightSelector;
use App\Services\Insights\NarratedInsight;
use App\Services\Insights\NarrationContext;
use App\Services\Insights\TemplateInsightNarrator;
use App\Services\Posting\InvoicePoster;
use App\Services\Stripe\StripePaymentService;
use App\Support\Security\SensitiveFeatureGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;

/*
 * security.two_factor_required_for (TWO_FACTOR_REQUIRED_FOR): a listed
 * feature runs only once the organization's Owner has confirmed two-factor
 * authentication, and the person using it, when someone is, has too.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-27 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->customer = Contact::create([
        'display_name' => 'Acme',
        'email' => 'buyer@acme.test',
        'is_customer' => true,
        'invoice_emails_enabled' => true,
        'reminder_emails_enabled' => true,
    ]);

    $this->gate = app(SensitiveFeatureGate::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

function requireTwoFactorFor(SensitiveFeature ...$features): void
{
    config(['security.two_factor_required_for' => array_map(fn (SensitiveFeature $f): string => $f->value, $features)]);
}

function confirmTwoFactorOn(User $user): User
{
    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

function gatePostedInvoice(Contact $customer, Account $income, string $dueDate = '2026-06-20'): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-'.str()->random(6),
        'invoice_date' => $dueDate,
        'due_date' => $dueDate,
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->refresh();
}

/** How many emails the array mailer has actually sent in this test. */
function gateSentMailCount(): int
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
}

/** Whether a Flux toast carrying $text was dispatched. */
function gateToastSaid(string $text): Closure
{
    return fn (string $event, array $params): bool => str_contains((string) data_get($params, 'slots.text'), $text);
}

describe('the rule', function () {
    it('requires nothing while nothing is listed', function () {
        foreach (SensitiveFeature::cases() as $feature) {
            expect($this->gate->requires($feature))->toBeFalse()
                ->and($this->gate->allows($feature, $this->company, $this->owner))->toBeTrue();
        }
    });

    it('ignores names it doesn’t know', function () {
        config(['security.two_factor_required_for' => ['customer_email', 'bogus', ' ai ']]);

        expect($this->gate->requires(SensitiveFeature::CustomerEmail))->toBeTrue()
            ->and($this->gate->requires(SensitiveFeature::Ai))->toBeTrue()
            ->and($this->gate->requires(SensitiveFeature::Api))->toBeFalse();
    });

    it('needs the owner, and the person acting, to have two-factor on', function () {
        requireTwoFactorFor(SensitiveFeature::Invitations);
        $member = User::factory()->create();
        $this->company->members()->attach($member, ['role' => CompanyRole::Admin->value]);

        // Nobody has it: the person acting is told about their own account first.
        expect($this->gate->refusal(SensitiveFeature::Invitations, $this->company, $member))
            ->toContain('on your account')->toContain('Settings → Security')
            // Background work has no actor: only the owner counts.
            ->and($this->gate->refusal(SensitiveFeature::Invitations, $this->company))
            ->toContain('owner')->toContain('Settings → Security');

        confirmTwoFactorOn($member);

        expect($this->gate->allows(SensitiveFeature::Invitations, $this->company, $member))->toBeFalse()
            ->and($this->gate->refusal(SensitiveFeature::Invitations, $this->company, $member))->toContain('owner');

        confirmTwoFactorOn($this->owner);

        expect($this->gate->allows(SensitiveFeature::Invitations, $this->company, $member))->toBeTrue()
            ->and($this->gate->allows(SensitiveFeature::Invitations, $this->company))->toBeTrue();
    });

    it('doesn’t count two-factor that was set up but never confirmed', function () {
        requireTwoFactorFor(SensitiveFeature::Api);
        $this->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => null])->save();

        expect($this->gate->allows(SensitiveFeature::Api, $this->company))->toBeFalse();
    });

    it('throws a client-safe exception from ensure()', function () {
        requireTwoFactorFor(SensitiveFeature::Api);

        expect(fn () => $this->gate->ensure(SensitiveFeature::Api, $this->company, $this->owner))
            ->toThrow(TwoFactorRequiredException::class, 'Settings → Security');
    });
});

describe('customer email', function () {
    it('refuses to send an invoice without two-factor, with a message saying why, and sends it with', function () {
        Notification::fake();
        requireTwoFactorFor(SensitiveFeature::CustomerEmail);
        $invoice = gatePostedInvoice($this->customer, $this->income);

        $page = fn () => Livewire::actingAs($this->owner)
            ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
            ->set('sendToEmail', 'buyer@acme.test')
            ->call('sendToClient');

        $page()->assertDispatched('toast-show', gateToastSaid('two-factor authentication'));
        Notification::assertNothingSent();
        expect(PortalLoginLink::query()->count())->toBe(0);

        confirmTwoFactorOn($this->owner);

        $page()->assertHasNoErrors();
        Notification::assertSentOnDemandTimes(InvoiceSharedNotification::class, 1);
    });

    it('skips a scheduled reminder while the owner lacks two-factor, then sends it', function () {
        Notification::fake();
        requireTwoFactorFor(SensitiveFeature::CustomerEmail);
        gatePostedInvoice($this->customer, $this->income); // 7 days overdue

        $this->artisan('reminders:send', ['--sync' => true])->assertSuccessful();

        Notification::assertNothingSent();
        expect(InvoiceReminderLog::query()->count())->toBe(0);

        confirmTwoFactorOn($this->owner);
        $this->artisan('reminders:send', ['--sync' => true])->assertSuccessful();

        Notification::assertSentOnDemandTimes(InvoiceReminderNotification::class, 1);
    });

    it('posts a recurring invoice but holds its auto-email', function () {
        Notification::fake();
        requireTwoFactorFor(SensitiveFeature::CustomerEmail);

        $schedule = app(SaveRecurringDocument::class)->handle([
            'document_type' => 'invoice',
            'contact_id' => $this->customer->id,
            'terms_id' => null,
            'memo' => 'Monthly retainer',
            'name' => 'Retainer',
            'frequency' => 'monthly',
            'start_date' => '2026-06-27',
            'day_of_month' => 27,
            'end_type' => 'never',
            'automation_mode' => 'post_and_email',
            'lines' => [[
                'item_id' => null,
                'account_id' => $this->income->id,
                'description' => 'Service',
                'quantity' => '1',
                'unit_price_cents' => 10000,
                'tax_code_id' => null,
            ]],
        ]);

        $this->artisan('recurring:generate', ['--sync' => true])->assertSuccessful();

        expect(Invoice::query()->where('recurring_document_id', $schedule->id)->firstOrFail()->status)->toBe(InvoiceStatus::Posted);
        Notification::assertNothingSent();
    });

    it('sends no portal sign-in link, answering as for an unknown address', function () {
        Notification::fake();
        requireTwoFactorFor(SensitiveFeature::CustomerEmail);

        app(RequestPortalLoginLink::class)->handle($this->company, 'buyer@acme.test');

        Notification::assertNothingSent();
        expect(PortalLoginLink::query()->count())->toBe(0);

        confirmTwoFactorOn($this->owner);
        app(RequestPortalLoginLink::class)->handle($this->company, 'buyer@acme.test');

        Notification::assertSentTo($this->customer, PortalLoginLinkNotification::class);
    });

    it('holds customer mail that gets past the checks, but never account mail', function () {
        requireTwoFactorFor(SensitiveFeature::CustomerEmail);
        $link = fn () => new PortalLoginLinkNotification('https://example.test/link', $this->company, 15);

        // To a contact, or to anyone outside the organization: held.
        $this->customer->notify($link());
        Notification::route('mail', 'outsider@example.test')->notify($link());

        expect(gateSentMailCount())->toBe(0);

        // To the organization's own members: not customer email.
        Notification::route('mail', [strtoupper($this->owner->email)])->notify($link());

        expect(gateSentMailCount())->toBe(1);

        // Account mail is never held.
        $this->owner->sendPasswordResetNotification('reset-token');

        expect(gateSentMailCount())->toBe(2);

        confirmTwoFactorOn($this->owner);
        $this->customer->notify($link());

        expect(gateSentMailCount())->toBe(3);
    });
});

describe('AI', function () {
    beforeEach(function () {
        $this->company->setInsightsState(['ai_narration' => true]);
        $this->company->setInboxState(['ocr_enabled' => true]);
    });

    it('writes the daily insight from the template while the owner lacks two-factor', function () {
        requireTwoFactorFor(SensitiveFeature::Ai);

        $ai = new class implements InsightNarrator
        {
            public int $calls = 0;

            public function narrate(array $candidates, NarrationContext $context): NarratedInsight
            {
                $this->calls++;

                return new NarratedInsight($candidates[0]->key, 'AI headline', 'AI body', InsightSource::Ai);
            }
        };

        $detector = new class implements InsightDetector
        {
            public function key(): string
            {
                return 'gate-test';
            }

            public function category(): InsightCategory
            {
                return InsightCategory::Hygiene;
            }

            public function detect(Company $company, CarbonImmutable $today): array
            {
                return [new InsightCandidate(key: 'gate-test', category: InsightCategory::Hygiene, score: 80, facts: [], headline: 'Template headline', body: 'Template body', urgent: false)];
            }

            public function cta(Company $company): ?array
            {
                return null;
            }
        };

        $generator = fn () => new DailyInsightGenerator(narrator: $ai, template: new TemplateInsightNarrator, selector: new InsightSelector, detectors: [$detector]);

        $insight = $generator()->generate($this->company, $this->company->currentDateTime());

        expect($insight->source)->toBe(InsightSource::Template)
            ->and($ai->calls)->toBe(0);

        $insight->delete();
        confirmTwoFactorOn($this->owner);

        expect($generator()->generate($this->company, $this->company->currentDateTime())->source)->toBe(InsightSource::Ai)
            ->and($ai->calls)->toBe(1);
    });

    it('leaves an inbox document for manual review in ProcessInboxItem while the owner lacks two-factor', function () {
        Storage::fake('local');
        requireTwoFactorFor(SensitiveFeature::Ai);

        $ocr = new class implements ReceiptIntelligence
        {
            public int $calls = 0;

            public function isEnabled(): bool
            {
                return true;
            }

            public function extract(string $absolutePath, string $mime): ?array
            {
                $this->calls++;

                return ['vendor' => 'Staples', 'amount_cents' => 4250];
            }

            public function lastError(): ?string
            {
                return null;
            }
        };

        $stage = function (): InboxItem {
            $item = InboxItem::create(['source' => 'upload', 'status' => 'pending', 'original_filename' => 'receipt.pdf', 'mime' => 'application/pdf', 'created_by_user_id' => $this->owner->id]);
            $path = 'attachments/'.$this->company->id.'/inbox_items/'.$item->id.'/'.Str::ulid().'.pdf';
            Storage::disk('local')->put($path, '%PDF-1.4 receipt');
            $attachment = Attachment::create([
                'attachable_type' => $item->getMorphClass(), 'attachable_id' => $item->id, 'disk' => 'local', 'path' => $path,
                'original_filename' => 'receipt.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 16, 'uploaded_by_id' => $this->owner->id,
            ]);
            $item->forceFill(['attachment_id' => $attachment->id])->save();

            return $item;
        };

        // The job is handed its intelligence before it binds the organization.
        $first = $stage->call($this);
        app()->forgetInstance('current_company');
        (new ProcessInboxItem($first->id))->handle($ocr);

        expect($ocr->calls)->toBe(0)
            ->and($first->fresh()->status->value)->toBe('needs_review')
            ->and($first->fresh()->extracted)->toBeNull();

        confirmTwoFactorOn($this->owner);
        app()->instance('current_company', $this->company);
        $second = $stage->call($this);
        app()->forgetInstance('current_company');
        (new ProcessInboxItem($second->id))->handle($ocr);

        expect($ocr->calls)->toBe(1)
            ->and($second->fresh()->extracted['vendor'])->toBe('Staples');
    });
});

describe('invitations, API keys, Stripe and MCP', function () {
    it('refuses to invite until the inviter has two-factor', function () {
        Notification::fake();
        requireTwoFactorFor(SensitiveFeature::Invitations);

        expect(fn () => app(InviteMember::class)->handle($this->company, $this->owner, 'new@example.test', CompanyRole::Accountant))
            ->toThrow(TwoFactorRequiredException::class);
        expect(CompanyInvitation::query()->count())->toBe(0);

        confirmTwoFactorOn($this->owner);
        app(InviteMember::class)->handle($this->company, $this->owner, 'new@example.test', CompanyRole::Accountant);

        expect(CompanyInvitation::query()->count())->toBe(1);
    });

    it('refuses to create an API key until two-factor is on', function () {
        requireTwoFactorFor(SensitiveFeature::Api);
        $this->owner->switchCompany($this->company);

        Livewire::actingAs($this->owner)
            ->test('pages::settings.api-keys')
            ->set('label', 'Integration')
            ->call('create')
            ->assertDispatched('toast-show', gateToastSaid('two-factor authentication'));

        expect(CompanyApiKey::query()->count())->toBe(0);

        confirmTwoFactorOn($this->owner);

        Livewire::actingAs($this->owner)
            ->test('pages::settings.api-keys')
            ->set('label', 'Integration')
            ->call('create')
            ->assertHasNoErrors();

        expect(CompanyApiKey::query()->count())->toBe(1);
    });

    it('sends the owner to turn on two-factor instead of to Stripe', function () {
        requireTwoFactorFor(SensitiveFeature::StripeConnect);
        $this->mock(StripePaymentService::class, fn ($mock) => $mock->shouldReceive('authorizeUrl')->andReturn('https://connect.stripe.test/oauth'));
        $this->actingAs($this->owner);

        $this->get(route('settings.stripe.connect', ['company' => $this->company->slug]))
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas('two_factor_required');

        confirmTwoFactorOn($this->owner);

        $this->get(route('settings.stripe.connect', ['company' => $this->company->slug]))
            ->assertRedirect('https://connect.stripe.test/oauth');
    });

    it('refuses the MCP consent screen, and the connection, without two-factor', function () {
        requireTwoFactorFor(SensitiveFeature::Api);

        $this->actingAs($this->owner)->get('/oauth/authorize?client_id=x&response_type=code')
            ->assertForbidden()
            ->assertSee('two-factor authentication');

        Passport::actingAs($this->owner, ['mcp:use']);
        $this->postJson("/mcp/business/{$this->company->slug}", ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
            ->assertForbidden();

        confirmTwoFactorOn($this->owner);

        expect($this->actingAs($this->owner)->get('/oauth/authorize?client_id=x&response_type=code')->getStatusCode())->not->toBe(403);

        Passport::actingAs($this->owner->fresh(), ['mcp:use']);
        $this->postJson("/mcp/business/{$this->company->slug}", ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
            ->assertOk();
    });
});

it('shows where a feature needs two-factor, and stops showing it once it is on', function () {
    requireTwoFactorFor(...SensitiveFeature::cases());
    config(['insights.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
    $this->owner->switchCompany($this->company);
    $invoice = gatePostedInvoice($this->customer, $this->income);

    $pages = fn () => [
        'ai' => Livewire::actingAs($this->owner)->test('pages::companies.edit', ['company' => $this->company]),
        'stripe_connect' => Livewire::actingAs($this->owner)->test('pages::companies.edit', ['company' => $this->company]),
        'api' => Livewire::actingAs($this->owner)->test('pages::settings.api-keys'),
        'invitations' => Livewire::actingAs($this->owner)->test('pages::settings.users-and-roles', ['company' => $this->company]),
        'customer_email' => Livewire::actingAs($this->owner)->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice]),
    ];

    foreach ($pages() as $feature => $page) {
        $page->assertSee('data-test="two-factor-required-'.$feature.'"', escape: false)
            ->assertSee('Requires two-factor authentication');
    }

    confirmTwoFactorOn($this->owner);

    foreach ($pages() as $feature => $page) {
        $page->assertDontSee('data-test="two-factor-required-'.$feature.'"', escape: false);
    }
});

it('changes nothing for anyone without two-factor while the list is empty', function () {
    Notification::fake();
    $invoice = gatePostedInvoice($this->customer, $this->income);

    Livewire::actingAs($this->owner)
        ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->set('sendToEmail', 'buyer@acme.test')
        ->call('sendToClient')
        ->assertHasNoErrors();

    app(InviteMember::class)->handle($this->company, $this->owner, 'new@example.test', CompanyRole::Accountant);

    Notification::assertSentOnDemandTimes(InvoiceSharedNotification::class, 1);
    expect(CompanyInvitation::query()->count())->toBe(1);
});
