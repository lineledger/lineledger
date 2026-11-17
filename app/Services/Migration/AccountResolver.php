<?php

namespace App\Services\Migration;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Services\Migration\Csv\StreamingGeneralLedgerReader;
use Illuminate\Support\Collection;

/**
 * Resolves QuickBooks account labels to LineLedger Account records, optionally
 * creating accounts that don't exist yet.
 *
 * QuickBooks labels accounts as "NUMBER · NAME" (e.g. "301 · Due to PA Franchise
 * Corp") and joins sub-accounts with a colon (e.g. "1000 · Bank:1010 · Chequing").
 * Resolution matches on the account number first, then the full path of names up
 * the parent chain (so "Hosting:Domain Names" and a top-level "Domain Names" stay
 * apart), then the leaf name (all case-insensitive). Auto-created accounts reuse
 * QuickBooks' own number and name.
 */
class AccountResolver
{
    /** @var Collection<string, Account> keyed by lowercased name and lowercased leaf name */
    protected Collection $byName;

    /** @var Collection<string, Account> keyed by lowercased account code */
    protected Collection $byCode;

    /** @var Collection<string, Account> keyed by QuickBooksAccountLabel::keyFor() of the names up the parent chain */
    protected Collection $byPath;

    /** @var array<string, AccountSubtype> lowercased name => subtype (from IIF !ACCNT records or an Account Listing) */
    protected array $typeHints;

    /** @var array<string, AccountSubtype> lowercased account code => subtype (from an Account Listing) */
    protected array $typeHintsByCode;

    protected int $nextCode;

    /**
     * @param  array<string, AccountSubtype>  $typeHints  keyed by lowercased account name
     * @param  array<string, AccountSubtype>  $typeHintsByCode  keyed by lowercased account code
     */
    public function __construct(
        protected int $companyId,
        protected bool $autoCreate = false,
        array $typeHints = [],
        array $typeHintsByCode = [],
    ) {
        $this->typeHints = $typeHints;
        $this->typeHintsByCode = $typeHintsByCode;
        $this->byName = collect();
        $this->byCode = collect();
        $this->byPath = collect();

        // Order deterministically: byName/byCode use last-write-wins, so when two
        // accounts share a name (e.g. a seeded "Undeposited Funds" plus one imported
        // from QuickBooks) the most recently created one must consistently win.
        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get(['id', 'parent_id', 'code', 'name', 'subtype', 'normal_balance']);

        $paths = QuickBooksAccountLabel::pathsOf($accounts);

        foreach ($accounts as $account) {
            $this->index($account, $paths[$account->id]);
        }

        $maxCode = (int) $accounts
            ->map(fn (Account $a) => is_numeric($a->code) ? (int) $a->code : 0)
            ->max();

        $this->nextCode = max($maxCode, 9000);
    }

    public function find(string $label): ?Account
    {
        $parsed = QuickBooksAccountLabel::leaf($label);

        // Account number is the most reliable match.
        if ($parsed['code'] !== null && $this->byCode->has(mb_strtolower($parsed['code']))) {
            return $this->byCode->get(mb_strtolower($parsed['code']));
        }

        // Then the whole path, so a sub-account isn't mistaken for another account
        // that happens to share its name.
        $path = QuickBooksAccountLabel::pathKey($label);

        if ($path !== '' && $this->byPath->has($path)) {
            return $this->byPath->get($path);
        }

        $candidates = [
            mb_strtolower(trim($label)),   // full label as stored (e.g. legacy imports)
            mb_strtolower($parsed['name']), // parsed leaf name
        ];

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate !== '' && $this->byName->has($candidate)) {
                return $this->byName->get($candidate);
            }
        }

        return null;
    }

    public function autoCreateEnabled(): bool
    {
        return $this->autoCreate;
    }

    /**
     * Find the account, creating it when auto-create is enabled. Returns null only
     * when the account is missing and auto-create is off.
     */
    public function resolveOrCreate(string $label): ?Account
    {
        $existing = $this->find($label);

        if ($existing !== null) {
            return $existing;
        }

        if (! $this->autoCreate) {
            return null;
        }

        $parsed = QuickBooksAccountLabel::leaf($label);

        // The account number is the most reliable hint key, then the path, then the name.
        $subtype = ($parsed['code'] !== null ? ($this->typeHintsByCode[mb_strtolower($parsed['code'])] ?? null) : null)
            ?? $this->typeHints[mb_strtolower(trim($label))]
            ?? $this->typeHints[QuickBooksAccountLabel::pathKey($label)]
            ?? $this->typeHints[mb_strtolower($parsed['name'])]
            ?? AccountSubtype::OtherAsset;

        return $this->createWithSubtype($parsed['name'], $subtype, $parsed['code']);
    }

    /**
     * Find an account by name, or create it with the given subtype regardless of
     * the auto-create flag. Used for internal accounts the importer must have
     * (e.g. a conversion rounding variance account).
     */
    public function ensure(string $name, AccountSubtype $subtype): Account
    {
        $existing = $this->find($name);

        if ($existing !== null) {
            return $existing;
        }

        return $this->createWithSubtype($name, $subtype);
    }

    protected function createWithSubtype(string $name, AccountSubtype $subtype, ?string $code = null): Account
    {
        // Reuse QuickBooks' own number when it's free; otherwise slot a synthetic one.
        $useCode = ($code !== null && $code !== '' && ! $this->byCode->has(mb_strtolower($code)))
            ? $code
            : (string) (++$this->nextCode);

        $account = Account::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'code' => $useCode,
            'name' => $name,
            'type' => $subtype->type(),
            'subtype' => $subtype,
            'normal_balance' => $subtype->type()->normalBalance(),
            'is_system' => false,
            'is_active' => true,
            'description' => 'Created during QuickBooks full-history import.',
        ]);

        // Created at the top level, so its path is its name.
        $this->index($account, $name);

        return $account;
    }

    protected function index(Account $account, string $path): void
    {
        $this->byName->put(mb_strtolower($account->name), $account);
        $this->byPath->put(QuickBooksAccountLabel::pathKey($path), $account);

        if ($account->code !== null && $account->code !== '') {
            $this->byCode->put(mb_strtolower((string) $account->code), $account);
        }

        if (str_contains($account->name, ':')) {
            $segments = explode(':', $account->name);
            $this->byName->put(mb_strtolower(trim(end($segments))), $account);
        }
    }

    /**
     * Convenience for building a resolver from an import context + source file.
     *
     * @param  array<string, AccountSubtype>  $typeHints  keyed by lowercased account name
     * @param  array<string, AccountSubtype>  $typeHintsByCode  keyed by lowercased account code
     */
    public static function for(int $companyId, bool $autoCreate, array $typeHints = [], array $typeHintsByCode = []): self
    {
        return new self($companyId, $autoCreate, $typeHints, $typeHintsByCode);
    }

    /**
     * Expose the IIF type mapping so callers can build hints without importing the reader.
     */
    public static function subtypeForIifType(string $type): AccountSubtype
    {
        return StreamingGeneralLedgerReader::subtypeForIifType($type);
    }
}
