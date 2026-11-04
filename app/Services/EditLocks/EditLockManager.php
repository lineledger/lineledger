<?php

namespace App\Services\EditLocks;

use App\Enums\Activity;
use App\Enums\EditLockState;
use App\Enums\PermissionAction;
use App\Exceptions\EditLocks\EditLockLostException;
use App\Exceptions\EditLocks\RecordEditLockedException;
use App\Models\Company;
use App\Models\User;
use App\Support\EditLocks\EditLockables;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Edit locks: one member edits a record at a time.
 *
 * Opening a record to edit takes a lease (token + expiry) on its `edit_locks`
 * row; the page renews it with a heartbeat and releases it on leave. Others
 * are refused while the lease is live. Every row also carries a random
 * `version`, replaced whenever someone *other than the holder* could have
 * written the record — another acquire or takeover, a guarded write from a
 * show page / list row / the API / the employee portal, a derived write
 * ({@see touch()}), or a takeover landing during the holder's own call. A
 * holder's save is accepted only while its token still holds AND the version
 * is the one it acquired, so a lapsed lease can't silently overwrite anything.
 * System writes (payments applied to an invoice, costing, balance recompute)
 * don't change the version, which is why `updated_at` isn't used.
 *
 * Mechanics — every state change is one compare-and-set UPDATE confirmed by a
 * SELECT, with no transaction and no lockForUpdate:
 *  - a locking read of a missing unique key takes a gap lock on MySQL's
 *    REPEATABLE READ, and two first-time acquires then deadlock;
 *  - MySQL reports *changed* rows, not matched rows, so affected-row counts are
 *    never trusted;
 *  - new rows use a plain insert that catches the unique violation (not
 *    insertOrIgnore, which turns FK/NOT NULL errors into silent warnings).
 * Don't call these inside DB::transaction — the row would stay locked until
 * the outer commit.
 *
 * Times are epoch milliseconds from PHP's clock (Carbon, so tests can travel).
 */
class EditLockManager
{
    /** @var array<int, Company|null> */
    private array $companies = [];

    public function enabled(): bool
    {
        return (bool) config('edit_locks.enabled', true);
    }

    public function ttlMs(): int
    {
        return max(1, (int) config('edit_locks.ttl_seconds', 120)) * 1000;
    }

    public function heartbeatMs(): int
    {
        return max(1, (int) config('edit_locks.heartbeat_seconds', 30)) * 1000;
    }

    public function idleMs(): int
    {
        return max(1, (int) config('edit_locks.idle_minutes', 15)) * 60_000;
    }

    /**
     * Take the lock for $user. The same user may always re-take their own lock
     * (a second tab, or the redirect back to the edit page after "Save draft");
     * doing so hands out a new token and version, so the older page can't save.
     *
     * @throws AuthorizationException when $takeOver is asked for by someone who isn't an Owner or Admin
     */
    public function acquire(Model $record, User $user, bool $takeOver = false): EditLockResult
    {
        if (! $this->enabled()) {
            return EditLockResult::disabled();
        }

        if ($takeOver && ! $this->canTakeOver($user, $record)) {
            throw new AuthorizationException(__('Only an owner or admin can take over editing.'));
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $now = $this->nowMs();
            $token = Str::random(40);
            $version = Str::random(20);
            $lease = [
                'token' => $token,
                'version' => $version,
                'user_id' => $user->id,
                'acquired_at_ms' => $now,
                'last_active_at_ms' => $now,
                'expires_at_ms' => $now + $this->ttlMs(),
            ];

            $query = $this->rows($record);

            if (! $takeOver) {
                $query->where(fn (Builder $q) => $q
                    ->whereNull('expires_at_ms')
                    ->orWhere('expires_at_ms', '<=', $now)
                    ->orWhereNull('user_id')
                    ->orWhere('user_id', $user->id));
            }

            $query->update($lease);

            $row = $this->rows($record)->first();

            if ($row === null) {
                try {
                    DB::table('edit_locks')->insert([
                        'company_id' => $record->getAttribute('company_id'),
                        'lockable_type' => $record::class,
                        'lockable_id' => $record->getKey(),
                        ...$lease,
                    ]);

                    return EditLockResult::acquired($token, $version, null);
                } catch (UniqueConstraintViolationException) {
                    continue; // someone inserted the row first — go round and compare-and-set
                }
            }

            if ($row->token === $token) {
                return EditLockResult::acquired($token, $version, $this->int($row->changed_at_ms));
            }

            return EditLockResult::blocked($this->holderFrom($row));
        }

        $row = $this->rows($record)->first();

        return EditLockResult::blocked($this->holderFrom($row));
    }

    /**
     * Confirm the page holding ($token, $version) may still write, renewing the
     * lease. A lease that lapsed is silently reclaimed when nothing changed
     * since — no other acquire, takeover or guarded write replaced the version.
     *
     * @throws EditLockLostException
     */
    public function verify(Model $record, string $token, string $version, User $user, bool $markActive = true): void
    {
        if (! $this->enabled()) {
            return;
        }

        $now = $this->nowMs();
        $renewal = [
            'token' => $token,
            'user_id' => $user->id,
            'expires_at_ms' => $now + $this->ttlMs(),
        ];

        // A keeper checking in on its own isn't the user doing anything; only
        // real interaction may postpone the idle pause.
        if ($markActive) {
            $renewal['last_active_at_ms'] = $now;
        }

        $this->rows($record)
            ->where('version', $version)
            ->where(fn (Builder $q) => $q
                ->where('token', $token)
                ->orWhereNull('expires_at_ms')
                ->orWhere('expires_at_ms', '<=', $now)
                ->orWhereNull('user_id'))
            ->update($renewal);

        $row = $this->rows($record)->first();

        if ($row !== null && $row->token === $token && $row->version === $version && $this->isHeld($row, $now)) {
            return;
        }

        throw $this->lostFrom($record, $row, $token, $user, $now);
    }

    /**
     * The lock state of an open page, without renewing or reclaiming anything.
     */
    public function inspect(Model $record, string $token, string $version, User $user): EditLockState
    {
        $now = $this->nowMs();
        $row = $this->rows($record)->first();

        if ($row !== null && $row->token === $token && $row->version === $version) {
            // Still this page's lock (live or lapsed with nothing changed): an
            // idle page stays paused rather than being quietly renewed.
            if ($this->int($row->last_active_at_ms) <= $now - $this->idleMs()) {
                return EditLockState::Idle;
            }

            if ($this->isHeld($row, $now)) {
                return EditLockState::Held;
            }
        }

        return $this->lostFrom($record, $row, $token, $user, $now)->state;
    }

    /**
     * After a request in which the holder's call ran: note that the record may
     * have changed (for the load-race check in {@see acquire()} callers), and if
     * the lease was taken over mid-call, invalidate the taker so their page —
     * which may have loaded data from before this call committed — can't save.
     */
    public function afterHolderCall(Model $record, string $token): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->rows($record)->update(['changed_at_ms' => $this->nowMs()]);
        $this->rows($record)->where('token', '<>', $token)->update(['version' => Str::random(20)]);
    }

    /**
     * Renew a lease from the browser keeper.
     *
     * @return array{status: 'held'|'idle'|'expired'|'lost', version?: string}
     */
    public function heartbeat(string $token, bool $active, User $user): array
    {
        $row = DB::table('edit_locks')->where('token', $token)->first();

        if ($row === null || $this->int($row->user_id) !== $user->id || ! $this->isMember($user, (int) $row->company_id)) {
            return ['status' => 'lost'];
        }

        $now = $this->nowMs();

        if (! $this->isHeld($row, $now)) {
            return ['status' => 'expired'];
        }

        if (! $active && $this->int($row->last_active_at_ms) <= $now - $this->idleMs()) {
            return ['status' => 'idle'];
        }

        $renewal = ['expires_at_ms' => $now + $this->ttlMs()];

        if ($active) {
            $renewal['last_active_at_ms'] = $now;
        }

        DB::table('edit_locks')->where('id', $row->id)->where('token', $token)->update($renewal);

        $row = DB::table('edit_locks')->where('id', $row->id)->first();

        if ($row === null || $row->token !== $token) {
            return ['status' => 'lost'];
        }

        return ['status' => 'held', 'version' => (string) $row->version];
    }

    /**
     * End a lease. Matches on the token, so a late release beacon from a page
     * the user already left can't free a lock their next page just took.
     */
    public function release(string $token, User $user): void
    {
        DB::table('edit_locks')
            ->where('token', $token)
            ->where('user_id', $user->id)
            ->whereNotNull('expires_at_ms')
            ->update(['expires_at_ms' => null]);
    }

    /**
     * Refuse a write while someone else holds the lock. A null actor (API key,
     * employee portal) is refused while anyone holds it.
     *
     * @throws RecordEditLockedException
     */
    public function assertWritable(Model $record, ?User $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        $now = $this->nowMs();
        $row = $this->rows($record)->first();

        if ($row === null || ! $this->isHeld($row, $now)) {
            return;
        }

        if ($actor !== null && $this->int($row->user_id) === $actor->id) {
            return;
        }

        throw new RecordEditLockedException(
            noun: EditLockables::noun($record),
            holder: $this->holderFrom($row),
            retryAfterSeconds: max(1, (int) ceil(($this->int($row->expires_at_ms) - $now) / 1000)),
        );
    }

    /**
     * Mark the record as changed by someone other than its holder, so an open
     * edit page can't save over it. For writes that shouldn't be refused but
     * do overwrite fields an editor may have open (derived writes).
     */
    public function touch(Model $record): void
    {
        if (! $this->enabled()) {
            return;
        }

        $now = $this->nowMs();
        $mark = ['version' => Str::random(20), 'changed_at_ms' => $now];

        $this->rows($record)->update($mark);

        if ($this->rows($record)->exists() || $record->getAttribute('company_id') === null) {
            return;
        }

        // Never opened for editing yet: record the write anyway, as a free row,
        // so an edit page that loaded the record just before it can't save over it.
        try {
            DB::table('edit_locks')->insert([
                'company_id' => $record->getAttribute('company_id'),
                'lockable_type' => $record::class,
                'lockable_id' => $record->getKey(),
                ...$mark,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->rows($record)->update(['version' => Str::random(20), 'changed_at_ms' => $this->nowMs()]);
        }
    }

    /**
     * Run a write that isn't the holder's own save (a show-page action, a list
     * row action, the API): refused while someone else holds the lock, and the
     * version is replaced afterwards whether or not the write succeeded.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws RecordEditLockedException
     */
    public function guardWrite(Model $record, ?User $actor, Closure $write): mixed
    {
        if (! $this->enabled()) {
            return $write();
        }

        $this->assertWritable($record, $actor);

        try {
            return $write();
        } finally {
            $this->touch($record);
        }
    }

    /**
     * {@see guardWrite()} for a page that loaded the record without taking the
     * lock (the employee portal): also refused if the version moved since the
     * page captured it with {@see currentVersion()}, or — given the time the page
     * loaded — if anyone saved the record since.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws RecordEditLockedException
     * @throws EditLockLostException
     */
    public function guardWriteAtVersion(Model $record, ?User $actor, ?string $expectedVersion, Closure $write, ?int $loadedAtMs = null): mixed
    {
        if (! $this->enabled()) {
            return $write();
        }

        $this->assertWritable($record, $actor);

        $row = $this->rows($record)->first();
        $changedAt = $this->int($row?->changed_at_ms);

        // A holder's own saves don't move the version, so also refuse anything
        // recorded as changed since the page loaded.
        if (($row->version ?? null) !== $expectedVersion || ($loadedAtMs !== null && $changedAt !== null && $changedAt >= $loadedAtMs)) {
            throw EditLockLostException::changed(EditLockables::noun($record));
        }

        try {
            return $write();
        } finally {
            $this->touch($record);
        }
    }

    public function currentVersion(Model $record): ?string
    {
        $version = $this->rows($record)->value('version');

        return $version === null ? null : (string) $version;
    }

    /**
     * Whoever holds a live lock on the record, unless it is $viewer.
     */
    public function holderOtherThan(Model $record, ?User $viewer): ?EditLockHolder
    {
        if (! $this->enabled()) {
            return null;
        }

        $row = $this->rows($record)->first();

        if ($row === null || ! $this->isHeld($row, $this->nowMs())) {
            return null;
        }

        if ($viewer !== null && $this->int($row->user_id) === $viewer->id) {
            return null;
        }

        return $this->holderFrom($row);
    }

    public function canTakeOver(User $user, Model $record): bool
    {
        $company = $this->company((int) $record->getAttribute('company_id'));

        return $company !== null
            && $user->permissions($company)->allows(Activity::EditLockTakeover, PermissionAction::Modify);
    }

    /**
     * Delete released rows nobody has touched for $days. Safe because versions
     * are random: an open page whose row was pruned gets "changed" on save,
     * never a silent pass.
     */
    public function prune(int $days): int
    {
        $cutoff = $this->nowMs() - (max(1, $days) * 86_400_000);

        // Released (or lapsed before the cutoff), and neither taken nor changed since.
        return DB::table('edit_locks')
            ->where(fn (Builder $q) => $q->whereNull('expires_at_ms')->orWhere('expires_at_ms', '<', $cutoff))
            ->where(fn (Builder $q) => $q->whereNull('changed_at_ms')->orWhere('changed_at_ms', '<', $cutoff))
            ->where(fn (Builder $q) => $q->whereNull('acquired_at_ms')->orWhere('acquired_at_ms', '<', $cutoff))
            ->delete();
    }

    public function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }

    private function rows(Model $record): Builder
    {
        return DB::table('edit_locks')
            ->where('lockable_type', $record::class)
            ->where('lockable_id', $record->getKey());
    }

    private function isHeld(object $row, int $now): bool
    {
        return $row->user_id !== null
            && $row->expires_at_ms !== null
            && $this->int($row->expires_at_ms) > $now;
    }

    private function lostFrom(Model $record, ?object $row, string $token, User $user, int $now): EditLockLostException
    {
        $noun = EditLockables::noun($record);

        if ($row !== null && $row->token !== $token && $this->isHeld($row, $now)) {
            return $this->int($row->user_id) === $user->id
                ? EditLockLostException::elsewhere($noun)
                : EditLockLostException::heldBy($noun, $this->holderFrom($row));
        }

        return EditLockLostException::changed($noun);
    }

    private function holderFrom(?object $row): EditLockHolder
    {
        $at = fn (mixed $ms): CarbonImmutable => CarbonImmutable::createFromTimestampMs($this->int($ms) ?? $this->nowMs());

        return new EditLockHolder(
            user: $row?->user_id !== null ? User::query()->find($row->user_id) : null,
            acquiredAt: $at($row?->acquired_at_ms),
            lastActiveAt: $at($row?->last_active_at_ms),
            expiresAt: $at($row?->expires_at_ms),
        );
    }

    private function isMember(User $user, int $companyId): bool
    {
        $company = $this->company($companyId);

        return $company !== null && $user->belongsToCompany($company);
    }

    private function company(int $id): ?Company
    {
        return $this->companies[$id] ??= Company::query()->find($id);
    }

    private function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
