<?php

use App\Enums\CompanyRole;
use App\Enums\SupportTicketStatus;
use App\Enums\SupportTicketType;
use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\Support\SupportTicketActivityNotification;
use App\Notifications\Support\SupportTicketReplyNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->user->switchCompany($this->company);
    $this->actingAs($this->user);
});

it('lets a user open a ticket and notifies every site admin', function () {
    Notification::fake();
    $admins = User::factory()->siteAdmin()->count(2)->create();

    Livewire::test('pages::support.index')
        ->set('subject', 'Invoices will not email')
        ->set('type', SupportTicketType::Bug->value)
        ->set('body', 'When I click send nothing happens.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $ticket = SupportTicket::query()->firstOrFail();
    expect($ticket->user_id)->toBe($this->user->id)
        ->and($ticket->company_id)->toBe($this->company->id)
        ->and($ticket->type)->toBe(SupportTicketType::Bug)
        ->and($ticket->status)->toBe(SupportTicketStatus::Open)
        ->and($ticket->messages()->count())->toBe(1)
        ->and($ticket->messages()->first()->from_admin)->toBeFalse();

    Notification::assertSentTo($admins, SupportTicketActivityNotification::class);
    Notification::assertNotSentTo([$this->user], SupportTicketActivityNotification::class);
});

it('requires a subject and body', function () {
    Livewire::test('pages::support.index')
        ->set('subject', '')
        ->set('body', '')
        ->call('save')
        ->assertHasErrors(['subject', 'body']);
});

it('emails the owner and flips to answered when a site admin replies', function () {
    Notification::fake();
    $admin = User::factory()->siteAdmin()->create();
    $ticket = SupportTicket::factory()->for($this->user, 'owner')->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.support-ticket-show', ['ticket' => $ticket])
        ->set('reply', 'Try re-sending — we pushed a fix.')
        ->call('sendReply')
        ->assertHasNoErrors();

    $ticket->refresh();
    expect($ticket->status)->toBe(SupportTicketStatus::Answered)
        ->and($this->user->unreadSupportRepliesCount())->toBe(1);

    Notification::assertSentTo($this->user, SupportTicketReplyNotification::class);
});

it('clears the unread badge when the owner opens the ticket', function () {
    $ticket = SupportTicket::factory()->for($this->user, 'owner')->answered()->create();
    $ticket->messages()->create(['user_id' => null, 'from_admin' => true, 'body' => 'Fixed on our end.']);

    expect($this->user->unreadSupportRepliesCount())->toBe(1);

    Livewire::test('pages::support.show', ['ticket' => $ticket])->assertOk();

    expect($this->user->fresh()->unreadSupportRepliesCount())->toBe(0);
});

it('reopens the ticket and notifies admins when the owner replies', function () {
    Notification::fake();
    $admins = User::factory()->siteAdmin()->count(2)->create();
    $ticket = SupportTicket::factory()->for($this->user, 'owner')->answered()->create();

    Livewire::test('pages::support.show', ['ticket' => $ticket])
        ->set('reply', 'Still broken for me.')
        ->call('sendReply')
        ->assertHasNoErrors();

    expect($ticket->fresh()->status)->toBe(SupportTicketStatus::Open);
    Notification::assertSentTo($admins, SupportTicketActivityNotification::class);
});

it('renders the admin queue with open tickets ordered first', function () {
    $admin = User::factory()->siteAdmin()->create();
    SupportTicket::factory()->for($this->user, 'owner')->resolved()->create(['subject' => 'Older resolved']);
    SupportTicket::factory()->for($this->user, 'owner')->create(['subject' => 'Fresh open one']);

    Livewire::actingAs($admin)
        ->test('pages::admin.support-tickets')
        ->assertOk()
        ->assertSee('Fresh open one')
        ->assertSee('Older resolved')
        ->set('statusFilter', 'open')
        ->assertSee('Fresh open one')
        ->assertDontSee('Older resolved');
});

it('lets a site admin mark a ticket resolved', function () {
    $admin = User::factory()->siteAdmin()->create();
    $ticket = SupportTicket::factory()->for($this->user, 'owner')->answered()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.support-ticket-show', ['ticket' => $ticket])
        ->call('markResolved');

    expect($ticket->fresh()->status)->toBe(SupportTicketStatus::Resolved);
});

it('forbids viewing another user\'s ticket', function () {
    $other = User::factory()->create();
    $ticket = SupportTicket::factory()->for($other, 'owner')->create();

    $this->get(route('support.show', $ticket))->assertNotFound();
});

it('hides the admin support console from non-admins', function () {
    $this->get('/admin/support')->assertNotFound();
});

it('shows the in-app support link and drops the external ones', function () {
    $this->get(route('support.index'))
        ->assertOk()
        ->assertSee('data-test="support-link"', false)
        ->assertDontSee('lineledger.com/requests')
        ->assertDontSee('lineledger.com/support');
});
