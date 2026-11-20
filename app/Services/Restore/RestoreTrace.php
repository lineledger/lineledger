<?php

namespace App\Services\Restore;

/**
 * What a restore did, kept for whoever needs to check its work: the backup
 * verifier pairs every bundle row with its copy through it.
 *
 * Pass an empty trace to {@see CompanyImporter::import()}; it is filled in as
 * the import runs and complete once the import returns.
 */
final class RestoreTrace
{
    /** Old → new row ids, per table. */
    public readonly IdMapper $ids;

    /**
     * Bundle user id → the user it was restored as on this instance.
     *
     * @var array<int, int>
     */
    public array $userIdMap = [];

    /**
     * Bundle user ids no user here matched by email, so their work is
     * attributed to the importing user.
     *
     * @var list<int>
     */
    public array $fallbackUserIds = [];

    public int $importingUserId = 0;

    public int $companyId = 0;

    /**
     * Bundle rows left out on purpose, per table (old ids).
     *
     * @var array<string, list<int>>
     */
    public array $skipped = [];

    /**
     * References to rows the bundle doesn't hold, restored as null (or 0 /
     * skipped per {@see RowTransformer::DANGLING_REFERENCE_POLICY}).
     *
     * @var list<array{table: string, column: string, id: int, table_referenced: string}>
     */
    public array $orphans = [];

    /**
     * @param  bool  $matchUsersByEmail  False restores as if onto an instance where
     *                                   none of the bundle's users exist: everyone's
     *                                   work falls to the importing user. The backup
     *                                   verifier's trial restore does this, so it
     *                                   never touches a real user's rows.
     */
    public function __construct(public readonly bool $matchUsersByEmail = true)
    {
        $this->ids = new IdMapper;
    }
}
