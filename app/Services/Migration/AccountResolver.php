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
 * Resolution parses the leaf segment into a code + name and matches on the account
 * number first, then the name (both case-insensitive). Auto-created accounts reuse
 * QuickBooks' own number and name.
 */
class AccountResolver
{
    /** @var Collection<string, Account> keyed by lowercased name and lowercased leaf name */
    protected Collection $byName;

    /** @var Collection<string, Account> keyed by lowercased account code */
    protected Collection $byCode;

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

        // Order deterministically: byName/byCode use last-write-wins, so when two
        // accounts share a name (e.g. a seeded "Undeposited Funds" plus one imported
        // from QuickBooks) the most recently created one must consistently win.
        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'subtype', 'normal_balance']);

        foreach ($accounts as $account) {
            $this->index($account);
        }

        $maxCode = (int) $accounts
            ->map(fn (Account $a) => is_numeric($a->code) ? (int) $a->code : 0)
            ->max();

        $this->nextCode = max($maxCode, 9000);
    }

    public function find(string $label): ?Account
    {
        $parsed = $this->parseLabel($label);

        // Account number is the most reliable match.
        if ($parsed['code'] !== null && $this->byCode->has(mb_strtolower($parsed['code']))) {
            return $this->byCode->get(mb_strtolower($parsed['code']));
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

        $parsed = $this->parseLabel($label);

        // The account number is the most reliable hint key, then the name.
        $subtype = ($parsed['code'] !== null ? ($this->typeHintsByCode[mb_strtolower($parsed['code'])] ?? null) : null)
            ?? $this->typeHints[mb_strtolower(trim($label))]
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

        $this->index($account);

        return $account;
    }

    protected function index(Account $account): void
    {
        $this->byName->put(mb_strtolower($account->name), $account);

        if ($account->code !== null && $account->code !== '') {
            $this->byCode->put(mb_strtolower((string) $account->code), $account);
        }

        if (str_contains($account->name, ':')) {
            $segments = explode(':', $account->name);
            $this->byName->put(mb_strtolower(trim(end($segments))), $account);
        }
    }

    /**
     * Split a QuickBooks account label into a code and name.
     *
     * "1000 · Bank:1010 · Chequing" → code "1010", name "Chequing"
     * "Due to Owner"                → code null,   name "Due to Owner"
     *
     * @return array{code: ?string, name: string}
     */
    protected function parseLabel(string $label): array
    {
        $segment = $label;

        if (str_contains($label, ':')) {
            $segments = explode(':', $label);
            $segment = (string) end($segments);
        }

        $segment = trim($segment);

        // QuickBooks separates the account number from the name with a middot.
        if (str_contains($segment, '·')) {
            [$code, $name] = array_pad(explode('·', $segment, 2), 2, '');
            $code = trim($code);
            $name = trim($name);

            if ($code !== '' && $name !== '') {
                return ['code' => $code, 'name' => $name];
            }
        }

        return ['code' => null, 'name' => $segment];
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
