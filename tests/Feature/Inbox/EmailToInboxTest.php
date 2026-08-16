<?php

use App\Enums\CompanyRole;
use App\Enums\InboxItemSource;
use App\Jobs\Inbox\ProcessInboxItem;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();

    config()->set('inbox.email.signing_secret', 'whsec_test_secret');
    config()->set('inbox.email.domain', 'inbox.example.com');

    $this->user = User::factory()->create(['email' => 'member@example.com']);
    $this->company = Company::factory()->create([
        'inbound_email_token' => 'tok_abc123',
        'inbound_email_enabled' => true,
    ]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    // Bind the tenant so the post-request assertions see the scoped inbox items.
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Post a Postmark-style inbound-parse payload, signing the exact raw body the
 * way the controller verifies it.
 *
 * @param  array<string, mixed>  $payload
 */
function postInbound(string $token, array $payload, ?string $signature = null)
{
    $body = json_encode($payload);
    $sig = $signature ?? hash_hmac('sha256', (string) $body, (string) config('inbox.email.signing_secret'));

    return test()->call(
        'POST',
        "/inbound-email/{$token}",
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INBOUND_SIGNATURE' => $sig,
        ],
        (string) $body,
    );
}

/**
 * @return array<string, mixed>
 */
function inboundPayload(string $from = 'member@example.com'): array
{
    return [
        'From' => $from,
        'Subject' => 'Receipt',
        'Attachments' => [
            [
                'Name' => 'receipt.pdf',
                'ContentType' => 'application/pdf',
                'Content' => base64_encode('%PDF-1.4 fake receipt'),
            ],
        ],
    ];
}

it('stages an inbox item and attachment from a valid signed webhook by a member', function () {
    $response = postInbound('tok_abc123', inboundPayload());

    $response->assertOk()->assertJson(['received' => true, 'staged' => 1]);

    $item = InboxItem::query()->sole();

    expect($item->source)->toBe(InboxItemSource::Email)
        ->and($item->sender_email)->toBe('member@example.com')
        ->and($item->original_filename)->toBe('receipt.pdf')
        ->and($item->created_by_user_id)->toBe($this->user->id)
        ->and($item->attachment_id)->not->toBeNull();

    $attachment = Attachment::query()->findOrFail($item->attachment_id);
    expect($attachment->attachable_type)->toBe($item->getMorphClass())
        ->and($attachment->attachable_id)->toBe($item->id)
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue();

    Queue::assertPushed(ProcessInboxItem::class, fn (ProcessInboxItem $job) => $job->inboxItemId === $item->id);
});

it('rejects a sender who is not a member of the company', function () {
    $response = postInbound('tok_abc123', inboundPayload('stranger@example.com'));

    $response->assertStatus(403);

    expect(InboxItem::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a webhook with a bad signature', function () {
    $response = postInbound('tok_abc123', inboundPayload(), signature: 'deadbeef');

    $response->assertStatus(403);

    expect(InboxItem::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns 404 when the company has inbound email disabled', function () {
    $this->company->forceFill(['inbound_email_enabled' => false])->save();

    $response = postInbound('tok_abc123', inboundPayload());

    $response->assertStatus(404);

    expect(InboxItem::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns 404 for an unknown routing token', function () {
    $response = postInbound('tok_unknown', inboundPayload());

    $response->assertStatus(404);

    expect(InboxItem::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
